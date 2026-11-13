<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Audit\AuditCategory;
use App\Audit\AuditChain;
use App\Audit\AuditEvents;
use App\Audit\AuditQuery;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Search, summary, export and integrity verification over an already-scoped audit query. */
trait ServesAuditLogs
{
    protected function search(Request $request, Builder $base): JsonResponse
    {
        $filters = $request->validate(AuditQuery::rules() + ['per_page' => ['sometimes', 'integer', 'between:1,100']]);
        $page = AuditQuery::apply($base, $filters)->with('actor')->orderByDesc('id')->cursorPaginate((int) ($filters['per_page'] ?? 50));

        return response()->json($page->through(fn (AuditLog $e) => $this->presentEntry($e)));
    }

    protected function summarize(Request $request, Builder $base): JsonResponse
    {
        $filters = $request->validate(AuditQuery::rules());
        $rows = AuditQuery::apply($base, $filters)->toBase()
            ->selectRaw('category, outcome, count(*) as total')->groupBy('category', 'outcome')->orderBy('category')->get();

        return response()->json(['data' => [
            'total' => (int) $rows->sum('total'),
            'by_category' => $rows->groupBy('category')->map(fn (Collection $g) => [
                'success' => (int) $g->firstWhere('outcome', 'success')?->total,
                'failure' => (int) $g->firstWhere('outcome', 'failure')?->total,
            ]),
        ]]);
    }

    protected function exportEntries(Request $request, Builder $base, ?Tenant $tenant): StreamedResponse
    {
        $data = $request->validate(AuditQuery::rules() + ['format' => ['required', Rule::in(['csv', 'json'])]]);
        $format = $data['format'];
        $filters = collect($data)->except('format')->all();
        $query = AuditQuery::apply($base, $filters)->with('actor');

        $rows = (clone $query)->count();
        if ($rows > config('audit.export.max_rows')) {
            throw ValidationException::withMessages(['from' => "The export would contain {$rows} entries; narrow the filters to at most ".config('audit.export.max_rows').'.']);
        }
        AuditLog::record(AuditEvents::AUDIT_EXPORTED, null, [], [], ['format' => $format, 'filters' => $filters, 'rows' => $rows],
            category: AuditCategory::DataExport, tenantId: $tenant?->id);

        // Streaming runs after the tenant middleware has finished, so tenant context is re-established here.
        $stream = function () use ($query, $format) {
            $out = fopen('php://output', 'w');
            $first = true;
            if ($format === 'csv') {
                fputcsv($out, ['id', 'created_at', 'category', 'event', 'outcome', 'actor_id', 'actor_name', 'actor_type', 'subject_type', 'subject_id', 'ip_address', 'http_method', 'route', 'request_id', 'old_values', 'new_values', 'metadata'], escape: '');
            } else {
                fwrite($out, '[');
            }
            foreach ($query->lazyById(500) as $entry) {
                $row = $this->presentEntry($entry);
                if ($format === 'csv') {
                    fputcsv($out, array_map(fn ($v) => self::csvCell($v), [
                        $row['id'], $row['created_at'], $row['category'], $row['event'], $row['outcome'], $row['actor']['id'] ?? null, $row['actor']['name'] ?? null,
                        $row['actor_type'], $row['subject']['type'] ?? null, $row['subject']['id'] ?? null, $row['ip_address'], $row['http_method'], $row['route'],
                        $row['request_id'], $row['old_values'], $row['new_values'], $row['metadata'],
                    ]), escape: '');
                } else {
                    fwrite($out, ($first ? '' : ',').json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                }
                $first = false;
            }
            if ($format === 'json') {
                fwrite($out, ']');
            }
            fclose($out);
        };

        return response()->streamDownload(fn () => $tenant ? Tenancy::run($tenant, $stream) : $stream(), 'audit-'.now()->format('Ymd-His').".{$format}", [
            'Content-Type' => $format === 'csv' ? 'text/csv; charset=UTF-8' : 'application/json',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    protected function verifyChains(?int $tenantId): JsonResponse
    {
        $results = DB::table('audit_chains')->where(fn ($q) => $tenantId ? $q->where('tenant_id', $tenantId) : $q->whereNull('tenant_id'))
            ->orderBy('chain')->pluck('chain')->map(fn (string $chain) => AuditChain::verify($chain))
            ->map(fn (array $r) => ['category' => Str::after($r['chain'], ':')] + collect($r)->except('chain')->all());
        $valid = $results->every(fn ($r) => $r['valid']);

        AuditLog::record(AuditEvents::AUDIT_INTEGRITY_VERIFIED, null, [], [], ['valid' => $valid, 'chains' => $results->count()],
            outcome: $valid ? 'success' : 'failure', category: AuditCategory::Administration, tenantId: $tenantId);

        return response()->json(['data' => ['valid' => $valid, 'verified_at' => now()->toIso8601String(), 'chains' => $results->values()]]);
    }

    protected function presentEntry(AuditLog $e, bool $evidence = false): array
    {
        return [
            'id' => $e->public_id,
            'created_at' => $e->created_at->toIso8601String(),
            'category' => $e->category->value,
            'event' => $e->event,
            'outcome' => $e->outcome,
            'actor' => $e->actor ? ['id' => $e->actor->public_id, 'name' => $e->actor->name] : null,
            'actor_type' => $e->actor_type,
            'subject' => $e->subject_type ? ['type' => Str::snake(class_basename($e->subject_type)), 'id' => $e->subject_public_id] : null,
            'old_values' => $e->old_values,
            'new_values' => $e->new_values,
            'metadata' => $e->metadata,
            'ip_address' => $e->ip_address,
            'user_agent' => $e->user_agent,
            'http_method' => $e->http_method,
            'route' => $e->route,
            'request_id' => $e->request_id,
        ] + ($evidence ? ['sequence' => $e->sequence, 'hash' => $e->hash] : []);
    }

    /** Neutralises spreadsheet formula injection. */
    private static function csvCell(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (string) $value;

        return preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }
}
