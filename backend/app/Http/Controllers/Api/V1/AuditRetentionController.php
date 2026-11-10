<?php

namespace App\Http\Controllers\Api\V1;

use App\Audit\AuditCategory;
use App\Audit\AuditEvents;
use App\Audit\Retention;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\SecurityEvent;
use App\Security\Permission;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AuditRetentionController extends Controller
{
    public function show(): JsonResponse
    {
        Gate::authorize(Permission::AuditView->value);

        return response()->json(['data' => $this->present()]);
    }

    public function update(Request $request): JsonResponse
    {
        Gate::authorize(Permission::AuditRetentionManage->value);
        [$min, $max] = [config('audit.retention.minimum_days'), config('audit.retention.maximum_days')];
        $data = $request->validate([
            'policies' => ['sometimes', 'array'],
            'policies.*' => ['required', 'integer', "between:{$min},{$max}"],
            'legal_hold' => ['sometimes', 'boolean'],
        ]);
        $unknown = array_diff(array_keys($data['policies'] ?? []), array_column(AuditCategory::cases(), 'value'));
        if ($unknown !== []) {
            throw ValidationException::withMessages(['policies' => 'Unknown audit categories: '.implode(', ', $unknown).'.']);
        }

        $tenant = Tenancy::current();
        $before = $this->present();

        DB::transaction(function () use ($data, $tenant, $request) {
            foreach ($data['policies'] ?? [] as $category => $days) {
                DB::table('audit_retention_policies')->updateOrInsert(
                    ['tenant_id' => $tenant->id, 'category' => $category],
                    ['retention_days' => $days, 'created_at' => now(), 'updated_at' => now()],
                );
            }
            if (array_key_exists('legal_hold', $data) && $data['legal_hold'] !== $tenant->audit_legal_hold) {
                $tenant->forceFill(['audit_legal_hold' => $data['legal_hold']])->save();
                SecurityEvent::record('audit.legal_hold_changed', 'warning', $request->user(), ['legal_hold' => $data['legal_hold']], audit: false);
            }
        });

        $after = $this->present();
        AuditLog::record(AuditEvents::AUDIT_RETENTION_CHANGED, $tenant, $before, $after, category: AuditCategory::Administration);

        return response()->json(['data' => $after]);
    }

    private function present(): array
    {
        $defaults = Retention::defaults();

        return [
            'legal_hold' => (bool) Tenancy::current()->fresh()->audit_legal_hold,
            'minimum_days' => config('audit.retention.minimum_days'),
            'maximum_days' => config('audit.retention.maximum_days'),
            'policies' => collect(Retention::effective(Tenancy::id()))->map(fn ($days, $category) => [
                'category' => $category, 'retention_days' => $days, 'default_days' => $defaults[$category], 'overridden' => $days !== $defaults[$category],
            ])->values(),
        ];
    }
}
