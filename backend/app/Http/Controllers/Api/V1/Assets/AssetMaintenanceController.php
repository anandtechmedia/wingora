<?php

namespace App\Http\Controllers\Api\V1\Assets;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\AssetMaintenanceRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Maintenance records. Work in progress takes an available asset out of service (in_maintenance);
 * completing or cancelling it puts the asset back. Assigned assets must be returned before work starts.
 */
class AssetMaintenanceController extends Controller
{
    use Concerns;

    private const KINDS = ['repair', 'service', 'inspection', 'upgrade'];

    private const NEXT = ['scheduled' => ['in_progress', 'completed', 'cancelled'], 'in_progress' => ['completed', 'cancelled'], 'completed' => [], 'cancelled' => []];

    public function index(Request $request, Asset $asset): JsonResponse
    {
        $this->manager($request);
        $records = $asset->maintenance()->orderByDesc('created_at')->orderByDesc('id')->limit(200)->get();

        return response()->json(['data' => $records->map(fn ($m) => self::presentRecord($m))->values()]);
    }

    public function store(Request $request, Asset $asset): JsonResponse
    {
        $this->manager($request);
        $data = $request->validate([
            'kind' => ['required', Rule::in(self::KINDS)],
            'description' => ['required', 'string', 'max:2000'],
            'vendor' => ['nullable', 'string', 'max:120'],
            'cost' => ['nullable', 'numeric', 'between:0,9999999999.99', 'required_with:currency'],
            'currency' => ['nullable', Rule::in(self::CURRENCIES), 'required_with:cost'],
            'status' => ['sometimes', Rule::in(['scheduled', 'in_progress'])],
            'scheduled_on' => ['nullable', 'date'],
        ]);
        $record = DB::transaction(function () use ($asset, $data, $request) {
            $a = Asset::query()->lockForUpdate()->findOrFail($asset->id);
            abort_if($a->status === 'retired', 422, __('Retired assets cannot be maintained.'));
            $status = $data['status'] ?? 'scheduled';
            if ($status === 'in_progress') {
                $this->takeOutOfService($a);
            }
            $m = (new AssetMaintenanceRecord)->forceFill([...$data, 'asset_id' => $a->id, 'status' => $status, 'created_by' => $request->user()->id, 'version' => 1]);
            $m->save();

            return $m;
        });

        return response()->json(['data' => self::presentRecord($record)], 201);
    }

    public function update(Request $request, AssetMaintenanceRecord $record): JsonResponse
    {
        $this->manager($request);
        $data = $request->validate([
            'description' => ['sometimes', 'string', 'max:2000'],
            'vendor' => ['nullable', 'string', 'max:120'],
            'cost' => ['nullable', 'numeric', 'between:0,9999999999.99'],
            'currency' => ['nullable', Rule::in(self::CURRENCIES)],
            'scheduled_on' => ['nullable', 'date'],
            'completed_on' => ['nullable', 'date'],
            'status' => ['sometimes', Rule::in(array_keys(self::NEXT))],
            'version' => ['required', 'integer'],
        ]);
        $record = DB::transaction(function () use ($record, $data) {
            $m = AssetMaintenanceRecord::query()->lockForUpdate()->findOrFail($record->id);
            $this->checkVersion($m, $data['version']);
            abort_unless(isset(self::NEXT[$m->status]) && self::NEXT[$m->status] !== [], 422, __('Completed or cancelled maintenance cannot be changed.'));
            $changes = collect($data)->except(['version', 'status'])->all();
            $cost = array_key_exists('cost', $changes) ? $changes['cost'] : $m->cost;
            $currency = array_key_exists('currency', $changes) ? $changes['currency'] : $m->currency;
            abort_if(($cost === null) !== ($currency === null), 422, __('Enter both a cost and its currency, or neither.'));
            $to = $data['status'] ?? $m->status;
            if ($to !== $m->status) {
                abort_unless(in_array($to, self::NEXT[$m->status], true), 422, __('This status change is not allowed.'));
                $a = Asset::query()->lockForUpdate()->findOrFail($m->asset_id);
                if ($to === 'in_progress') {
                    abort_if($a->status === 'retired', 422, __('Retired assets cannot be maintained.'));
                    $this->takeOutOfService($a);
                } elseif ($m->status === 'in_progress' && $a->status === 'in_maintenance' && ! $this->otherWorkInProgress($m)) {
                    $a->forceFill(['status' => 'available', 'version' => $a->version + 1])->save();
                }
                if ($to === 'completed') {
                    $changes['completed_on'] ??= now()->toDateString();
                }
            }
            $m->forceFill($changes + ['status' => $to, 'version' => $m->version + 1])->save();

            return $m;
        });

        return response()->json(['data' => self::presentRecord($record)]);
    }

    private function takeOutOfService(Asset $a): void
    {
        abort_if($a->status === 'assigned', 422, __('Return the asset before starting maintenance.'));
        if ($a->status === 'available') {
            $a->forceFill(['status' => 'in_maintenance', 'version' => $a->version + 1])->save();
        }
    }

    private function otherWorkInProgress(AssetMaintenanceRecord $m): bool
    {
        return AssetMaintenanceRecord::query()->where('asset_id', $m->asset_id)->where('id', '!=', $m->id)->where('status', 'in_progress')->exists();
    }

    public static function presentRecord(AssetMaintenanceRecord $m): array
    {
        return [
            'id' => $m->public_id, 'kind' => $m->kind, 'description' => $m->description, 'vendor' => $m->vendor,
            'cost' => $m->cost, 'currency' => $m->currency, 'status' => $m->status,
            'scheduled_on' => $m->scheduled_on?->toDateString(), 'completed_on' => $m->completed_on?->toDateString(),
            'created_at' => $m->created_at?->toIso8601String(), 'version' => $m->version,
        ];
    }
}
