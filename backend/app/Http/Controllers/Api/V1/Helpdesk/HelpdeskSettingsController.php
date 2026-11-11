<?php

namespace App\Http\Controllers\Api\V1\Helpdesk;

use App\Audit\AuditEvents;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\HelpdeskCategory;
use App\Models\HelpdeskSetting;
use App\Models\User;
use App\Security\Permission as P;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class HelpdeskSettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission(P::HelpdeskConfigure), 403);

        return response()->json(['data' => ['due_hours' => HelpdeskSetting::current()->due_hours]]);
    }

    public function update(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission(P::HelpdeskConfigure), 403);
        $data = $request->validate(['due_hours' => ['required', 'array'], 'due_hours.*' => ['nullable', 'integer', 'between:1,2160']]);
        $settings = HelpdeskSetting::current();
        $before = $settings->due_hours;
        // Omitted priorities keep their target; an explicit null clears it.
        $hours = collect(['low', 'normal', 'high', 'urgent'])->mapWithKeys(fn ($p) => [$p => array_key_exists($p, $data['due_hours']) ? $data['due_hours'][$p] : ($before[$p] ?? null)])->all();
        $settings->forceFill(['due_hours' => $hours])->save();
        AuditLog::record(AuditEvents::HELPDESK_SETTINGS_CHANGED, $settings, ['due_hours' => $before], ['due_hours' => $hours]);

        return response()->json(['data' => ['due_hours' => $hours]]);
    }

    /** Submitters see active categories (for filing); configurators also see inactive ones and the restricted agent lists. */
    public function categories(Request $request): JsonResponse
    {
        $user = $request->user();
        $configure = $user->hasPermission(P::HelpdeskConfigure);
        abort_unless($configure || $user->hasPermission(P::HelpdeskSubmit) || $user->hasPermission(P::HelpdeskAgent), 403);
        $items = HelpdeskCategory::query()->when(! $configure, fn ($q) => $q->where('active', true))
            ->when($configure, fn ($q) => $q->with('agents'))->orderBy('sort_order')->orderBy('name')->get();

        return response()->json(['data' => $items->map(fn ($c) => $this->present($c, $configure))->values()]);
    }

    public function storeCategory(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission(P::HelpdeskConfigure), 403);
        $data = $request->validate($this->rules(null));
        $category = new HelpdeskCategory;
        $this->apply($category, $data);

        return response()->json(['data' => $this->present($category->load('agents'), true)], 201);
    }

    public function updateCategory(Request $request, HelpdeskCategory $category): JsonResponse
    {
        abort_unless($request->user()->hasPermission(P::HelpdeskConfigure), 403);
        $this->apply($category, $request->validate($this->rules($category)));

        return response()->json(['data' => $this->present($category->load('agents'), true)]);
    }

    private function rules(?HelpdeskCategory $category): array
    {
        $req = $category ? 'sometimes' : 'required';

        return [
            'name' => [$req, 'string', 'min:2', 'max:80', Rule::unique('helpdesk_categories', 'name')->where('tenant_id', $category?->tenant_id ?? request()->user()->tenant_id)->ignore($category?->id)],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'restricted' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'between:0,1000'],
            'agent_ids' => ['sometimes', 'array', 'max:50'],
            'agent_ids.*' => ['string', 'max:26'],
        ];
    }

    private function apply(HelpdeskCategory $category, array $data): void
    {
        $users = null;
        if (array_key_exists('agent_ids', $data)) {
            $users = User::query()->where('tenant_id', request()->user()->tenant_id)->whereIn('public_id', $data['agent_ids'])->get();
            abort_if($users->count() !== count(array_unique($data['agent_ids'])), 422, __('Unknown agent.'));
        }
        $category->fill(array_intersect_key($data, array_flip($category->getFillable())))->save();
        if ($users !== null) {
            $category->agents()->sync($users->mapWithKeys(fn ($u) => [$u->id => ['tenant_id' => $category->tenant_id]])->all());
            AuditLog::record(AuditEvents::HELPDESK_SETTINGS_CHANGED, $category, [], ['agents' => $users->pluck('public_id')->all()]);
        }
    }

    private function present(HelpdeskCategory $c, bool $configure): array
    {
        $out = ['id' => $c->public_id, 'name' => $c->name, 'description' => $c->description, 'restricted' => $c->restricted, 'active' => $c->active, 'sort_order' => $c->sort_order];
        if ($configure) {
            $out['agents'] = $c->agents->map(fn ($u) => ['id' => $u->public_id, 'name' => $u->name])->values();
        }

        return $out;
    }
}
