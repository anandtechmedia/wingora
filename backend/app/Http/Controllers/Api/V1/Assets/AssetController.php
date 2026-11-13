<?php

namespace App\Http\Controllers\Api\V1\Assets;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\AssetCategory;
use App\Models\Employee;
use App\Security\Permission as P;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Asset registry, assignment and return. Managing assets needs assets.manage; everyone with assets.view
 * sees only the assets assigned to them through `mine`. Status changes go through assign, return,
 * maintenance and retire so the registry and assignment history always agree.
 */
class AssetController extends Controller
{
    use Concerns;

    public const STATUSES = ['available', 'assigned', 'in_maintenance', 'retired'];

    public function index(Request $request): JsonResponse
    {
        $this->manager($request);
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'category' => ['nullable', 'string', 'max:26'],
            'employee' => ['nullable', 'string', 'max:26'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);
        $query = Asset::query()->with(['category', 'currentEmployee']);
        if ($term = $data['q'] ?? null) {
            $like = '%'.addcslashes($term, '%_\\').'%';
            $query->where(fn ($w) => $w->where('asset_tag', 'like', $like)->orWhere('name', 'like', $like)->orWhere('serial_number', 'like', $like));
        }
        $query->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($data['category'] ?? null, fn ($q, $c) => $q->whereIn('category_id', AssetCategory::query()->where('public_id', $c)->select('id')))
            ->when($data['employee'] ?? null, fn ($q, $e) => $q->whereIn('current_employee_id', Employee::query()->where('public_id', $e)->select('id')));
        $page = $query->orderBy('asset_tag')->paginate((int) ($data['per_page'] ?? 25));

        return response()->json(['data' => $page->getCollection()->map(fn ($a) => $this->present($a))->values(), 'meta' => $this->meta($page)]);
    }

    /** The signed-in employee's current and past assignments. */
    public function mine(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission(P::AssetsView) || $request->user()->hasPermission(P::AssetsManage), 403);
        $data = $request->validate(['per_page' => ['nullable', 'integer', 'between:1,100']]);
        $me = $request->user()->employee?->id ?? 0;
        $page = AssetAssignment::query()->with('asset.category')->where('employee_id', $me)
            ->orderByRaw('returned_on is null desc')->orderByDesc('assigned_on')->orderByDesc('id')
            ->paginate((int) ($data['per_page'] ?? 25));

        return response()->json(['data' => $page->getCollection()->map(fn ($a) => $this->presentAssignment($a, true))->values(), 'meta' => $this->meta($page)]);
    }

    public function show(Request $request, Asset $asset): JsonResponse
    {
        $this->manager($request);

        return response()->json(['data' => $this->present($asset->load(['category', 'currentEmployee']))]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->manager($request);
        $data = $this->validated($request, true);
        $category = $this->category($data['category_id'], true);
        $this->ensureUniqueTag($data['asset_tag']);
        $asset = (new Asset)->forceFill([
            ...collect($data)->except(['category_id'])->all(),
            'category_id' => $category->id,
            'status' => 'available',
            'condition' => $data['condition'] ?? 'good',
            'created_by' => $request->user()->id,
            'version' => 1,
        ]);
        $asset->save();

        return response()->json(['data' => $this->present($asset->load(['category', 'currentEmployee']))], 201);
    }

    public function update(Request $request, Asset $asset): JsonResponse
    {
        $this->manager($request);
        $data = $this->validated($request, false) + $request->validate([
            'version' => ['required', 'integer'],
            'status' => ['sometimes', Rule::in(['available', 'retired'])],
        ]);
        $asset = DB::transaction(function () use ($asset, $data) {
            $a = Asset::query()->lockForUpdate()->findOrFail($asset->id);
            $this->checkVersion($a, $data['version']);
            if (isset($data['status']) && $data['status'] !== $a->status) {
                // Retiring and reinstating are the only direct status changes; assigned or in-maintenance assets must be returned or finished first.
                abort_unless(in_array($a->status, ['available', 'retired'], true), 422, __('Return the asset or finish its maintenance first.'));
            }
            abort_if($a->status === 'retired' && ($data['status'] ?? 'retired') === 'retired' && count(array_diff(array_keys($data), ['version', 'status', 'notes'])) > 0, 422, __('Retired assets cannot be edited. Reinstate the asset first.'));
            if (isset($data['asset_tag']) && $data['asset_tag'] !== $a->asset_tag) {
                $this->ensureUniqueTag($data['asset_tag'], $a->id);
            }
            $changes = collect($data)->except(['version', 'category_id'])->all();
            if (isset($data['category_id'])) {
                $changes['category_id'] = $this->category($data['category_id'], $data['category_id'] !== $a->category?->public_id)->id;
            }
            $this->checkWarranty($changes + $a->only(['purchased_on', 'warranty_ends_on']));
            $a->forceFill($changes + ['version' => $a->version + 1])->save();

            return $a;
        });

        return response()->json(['data' => $this->present($asset->load(['category', 'currentEmployee']))]);
    }

    public function assign(Request $request, Asset $asset): JsonResponse
    {
        $this->manager($request);
        $data = $request->validate([
            'employee_id' => ['required', 'string', 'max:26'],
            'assigned_on' => ['nullable', 'date'],
            'due_back_on' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'version' => ['required', 'integer'],
        ]);
        $employee = Employee::query()->where('public_id', $data['employee_id'])->firstOr(fn () => throw ValidationException::withMessages(['employee_id' => __('Choose an employee in this organization.')]));
        $assignedOn = Carbon::parse($data['assigned_on'] ?? now()->toDateString())->startOfDay();
        if (isset($data['due_back_on']) && Carbon::parse($data['due_back_on'])->lt($assignedOn)) {
            throw ValidationException::withMessages(['due_back_on' => __('The return date must be on or after the assignment date.')]);
        }
        $asset = DB::transaction(function () use ($asset, $data, $employee, $assignedOn, $request) {
            $a = Asset::query()->lockForUpdate()->findOrFail($asset->id);
            $this->checkVersion($a, $data['version']);
            abort_unless($a->status === 'available', 422, __('Only available assets can be assigned.'));
            (new AssetAssignment)->forceFill([
                'asset_id' => $a->id, 'employee_id' => $employee->id, 'assigned_on' => $assignedOn, 'due_back_on' => $data['due_back_on'] ?? null,
                'condition_out' => $a->condition, 'notes' => $data['notes'] ?? null, 'assigned_by' => $request->user()->id,
            ])->save();
            $a->forceFill(['status' => 'assigned', 'current_employee_id' => $employee->id, 'version' => $a->version + 1])->save();

            return $a;
        });

        return response()->json(['data' => $this->present($asset->load(['category', 'currentEmployee']))]);
    }

    public function return(Request $request, Asset $asset): JsonResponse
    {
        $this->manager($request);
        $data = $request->validate([
            'condition' => ['required', Rule::in(self::CONDITIONS)],
            'returned_on' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'version' => ['required', 'integer'],
        ]);
        $asset = DB::transaction(function () use ($asset, $data, $request) {
            $a = Asset::query()->lockForUpdate()->findOrFail($asset->id);
            $this->checkVersion($a, $data['version']);
            abort_unless($a->status === 'assigned', 422, __('This asset is not assigned.'));
            $open = AssetAssignment::query()->where('asset_id', $a->id)->whereNull('returned_on')->lockForUpdate()->firstOrFail();
            $returnedOn = Carbon::parse($data['returned_on'] ?? now()->toDateString())->startOfDay();
            if ($returnedOn->lt($open->assigned_on)) {
                throw ValidationException::withMessages(['returned_on' => __('The return date must be on or after the assignment date.')]);
            }
            $open->forceFill(['returned_on' => $returnedOn, 'condition_in' => $data['condition'], 'return_notes' => $data['notes'] ?? null, 'returned_by' => $request->user()->id])->save();
            $a->forceFill(['status' => 'available', 'condition' => $data['condition'], 'current_employee_id' => null, 'version' => $a->version + 1])->save();

            return $a;
        });

        return response()->json(['data' => $this->present($asset->load(['category', 'currentEmployee']))]);
    }

    /** Full assignment and maintenance history, newest first. */
    public function history(Request $request, Asset $asset): JsonResponse
    {
        $this->manager($request);
        $assignments = $asset->assignments()->with(['employee', 'assigner', 'returner'])->orderByDesc('assigned_on')->orderByDesc('id')->limit(200)->get();
        $maintenance = $asset->maintenance()->orderByDesc('created_at')->orderByDesc('id')->limit(200)->get();

        return response()->json(['data' => [
            'assignments' => $assignments->map(fn ($a) => $this->presentAssignment($a, false))->values(),
            'maintenance' => $maintenance->map(fn ($m) => AssetMaintenanceController::presentRecord($m))->values(),
        ]]);
    }

    private function validated(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';
        $data = $request->validate([
            'category_id' => [$required, 'string', 'max:26'],
            'asset_tag' => [$required, 'string', 'max:40', 'regex:/^[A-Za-z0-9._\-\/]+$/'],
            'name' => [$required, 'string', 'max:120'],
            'serial_number' => ['nullable', 'string', 'max:120'],
            'condition' => ['sometimes', Rule::in(self::CONDITIONS)],
            'purchased_on' => ['nullable', 'date'],
            'purchase_cost' => ['nullable', 'numeric', 'between:0,9999999999.99', 'required_with:currency'],
            'currency' => ['nullable', Rule::in(self::CURRENCIES), 'required_with:purchase_cost'],
            'warranty_ends_on' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        if ($creating) {
            $this->checkWarranty($data);
        }

        return $data;
    }

    private function checkWarranty(array $values): void
    {
        $from = $values['purchased_on'] ?? null;
        $to = $values['warranty_ends_on'] ?? null;
        if ($from && $to && Carbon::parse($to)->lt(Carbon::parse($from))) {
            throw ValidationException::withMessages(['warranty_ends_on' => __('The warranty end date must be on or after the purchase date.')]);
        }
    }

    private function category(string $publicId, bool $mustBeActive): AssetCategory
    {
        return AssetCategory::query()->where('public_id', $publicId)->when($mustBeActive, fn ($q) => $q->where('active', true))
            ->firstOr(fn () => throw ValidationException::withMessages(['category_id' => __('Choose an active asset category.')]));
    }

    private function ensureUniqueTag(string $tag, ?int $except = null): void
    {
        if (Asset::query()->where('asset_tag', $tag)->when($except, fn ($q) => $q->where('id', '!=', $except))->exists()) {
            throw ValidationException::withMessages(['asset_tag' => __('Another asset already uses this tag.')]);
        }
    }

    private function present(Asset $a): array
    {
        return [
            'id' => $a->public_id, 'asset_tag' => $a->asset_tag, 'name' => $a->name, 'serial_number' => $a->serial_number,
            'category' => $a->category ? ['id' => $a->category->public_id, 'name' => $a->category->name] : null,
            'status' => $a->status, 'condition' => $a->condition,
            'purchased_on' => $a->purchased_on?->toDateString(), 'purchase_cost' => $a->purchase_cost, 'currency' => $a->currency,
            'warranty_ends_on' => $a->warranty_ends_on?->toDateString(), 'notes' => $a->notes,
            'assigned_to' => $this->person($a->currentEmployee), 'version' => $a->version,
        ];
    }

    private function presentAssignment(AssetAssignment $a, bool $withAsset): array
    {
        return [
            'id' => $a->public_id,
            ...($withAsset ? ['asset' => ['id' => $a->asset->public_id, 'asset_tag' => $a->asset->asset_tag, 'name' => $a->asset->name, 'serial_number' => $a->asset->serial_number, 'category' => $a->asset->category?->name]] : []),
            ...($withAsset ? [] : ['employee' => $this->person($a->employee), 'assigned_by' => $a->assigner?->name, 'returned_by' => $a->returner?->name]),
            'assigned_on' => $a->assigned_on->toDateString(), 'due_back_on' => $a->due_back_on?->toDateString(), 'returned_on' => $a->returned_on?->toDateString(),
            'condition_out' => $a->condition_out, 'condition_in' => $a->condition_in, 'notes' => $a->notes, 'return_notes' => $a->return_notes,
        ];
    }
}
