<?php

namespace App\Audit;

use App\Models\AuditLog;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/** Per-tenant, per-category retention. Pruning removes the oldest prefix of each chain and is itself audited. */
final class Retention
{
    /** @return array<string, int> */
    public static function defaults(): array
    {
        $config = config('audit.retention');

        return collect(AuditCategory::cases())->mapWithKeys(fn (AuditCategory $c) => [
            $c->value => (int) ($config['categories'][$c->value] ?? $config['default_days']),
        ])->all();
    }

    /** @return array<string, int> */
    public static function effective(?int $tenantId): array
    {
        $overrides = $tenantId ? DB::table('audit_retention_policies')->where('tenant_id', $tenantId)->pluck('retention_days', 'category')->all() : [];

        return array_map('intval', array_merge(self::defaults(), array_intersect_key($overrides, self::defaults())));
    }

    /** @return list<array{chain: string, deleted: int, through_sequence: int}> */
    public static function prune(bool $dryRun = false): array
    {
        $results = [];
        $held = Tenant::query()->where('audit_legal_hold', true)->pluck('id')->all();

        foreach (DB::table('audit_chains')->orderBy('id')->get() as $state) {
            if ($state->tenant_id && in_array((int) $state->tenant_id, $held, true)) {
                continue;
            }
            $days = self::effective($state->tenant_id ? (int) $state->tenant_id : null)[$state->category] ?? config('audit.retention.default_days');
            $cutoff = now()->subDays($days)->format('Y-m-d H:i:s');
            $boundary = DB::table('audit_logs')->where('chain', $state->chain)->where('created_at', '<', $cutoff)->orderByDesc('sequence')->first(['sequence', 'hash']);
            if (! $boundary) {
                continue;
            }

            $deleted = $dryRun ? DB::table('audit_logs')->where('chain', $state->chain)->where('sequence', '<=', $boundary->sequence)->count()
                : DB::transaction(function () use ($state, $boundary) {
                    DB::table('audit_chains')->where('id', $state->id)->lockForUpdate()->first();
                    $count = DB::table('audit_logs')->where('chain', $state->chain)->where('sequence', '<=', $boundary->sequence)->delete();
                    DB::table('audit_chains')->where('id', $state->id)->update(['anchor_sequence' => $boundary->sequence, 'anchor_hash' => $boundary->hash, 'updated_at' => now()]);

                    return $count;
                });

            $result = ['chain' => $state->chain, 'deleted' => $deleted, 'through_sequence' => (int) $boundary->sequence];
            $results[] = $result;

            if (! $dryRun) {
                AuditLog::record(AuditEvents::AUDIT_RETENTION_PRUNED, null, [], [], $result + ['anchor_hash' => $boundary->hash, 'cutoff' => $cutoff, 'retention_days' => $days],
                    category: AuditCategory::Administration, tenantId: $state->tenant_id ? (int) $state->tenant_id : null);
            }
        }

        return $results;
    }
}
