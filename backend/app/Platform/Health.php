<?php

namespace App\Platform;

use App\Models\DataExport;
use App\Models\DeletionRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Throwable;

/** Operational checks for the platform console. Reports state only; never configuration values or secrets. */
final class Health
{
    public const HEARTBEAT_KEY = 'platform:scheduler-heartbeat';

    public function report(): array
    {
        $checks = [
            'database' => $this->probe(fn () => DB::select('select 1')),
            'cache' => $this->probe(function () {
                Cache::put('platform:health-probe', $v = bin2hex(random_bytes(4)), 30);
                throw_unless(Cache::get('platform:health-probe') === $v, new \RuntimeException('read back failed'));
            }),
            'queue' => $this->queue(),
            'scheduler' => $this->scheduler(),
            'webhooks' => $this->webhooks(),
        ];
        $checks['data'] = [
            'status' => 'ok',
            'exports_failed_24h' => DataExport::query()->withoutGlobalScopes()->where('status', 'failed')->where('updated_at', '>=', now()->subDay())->count(),
            'deletions_scheduled' => DeletionRequest::query()->withoutGlobalScopes()->where('status', 'scheduled')->count(),
            'deletions_in_progress' => DeletionRequest::query()->withoutGlobalScopes()->where('status', 'purging')->count(),
        ];
        $status = collect($checks)->contains(fn ($c) => $c['status'] === 'down') ? 'down'
            : (collect($checks)->contains(fn ($c) => $c['status'] === 'degraded') ? 'degraded' : 'ok');

        return ['status' => $status, 'checked_at' => now()->toIso8601String(), 'checks' => $checks];
    }

    private function probe(callable $check): array
    {
        $start = hrtime(true);
        try {
            $check();

            return ['status' => 'ok', 'latency_ms' => round((hrtime(true) - $start) / 1e6, 1)];
        } catch (Throwable $e) {
            report($e);

            return ['status' => 'down', 'latency_ms' => null];
        }
    }

    private function queue(): array
    {
        $failed = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : null;
        $recent = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count() : 0;
        try {
            $size = Queue::size();
        } catch (Throwable) {
            return ['status' => 'down', 'connection' => config('queue.default'), 'pending' => null, 'failed' => $failed, 'failed_24h' => $recent];
        }

        return ['status' => $recent > 0 || $size > 1000 ? 'degraded' : 'ok', 'connection' => config('queue.default'), 'pending' => $size, 'failed' => $failed, 'failed_24h' => $recent];
    }

    private function scheduler(): array
    {
        $beat = Cache::get(self::HEARTBEAT_KEY);
        $last = is_int($beat) ? CarbonImmutable::createFromTimestamp($beat) : null;

        return ['status' => $last && $last->gt(now()->subMinutes(5)) ? 'ok' : 'degraded', 'last_run_at' => $last?->toIso8601String()];
    }

    private function webhooks(): array
    {
        $since = now()->subDay();
        $outcomes = DB::table('billing_events')->where('created_at', '>=', $since)->selectRaw('outcome, count(*) as c')->groupBy('outcome')->pluck('c', 'outcome');
        $rejected = DB::table('security_events')->where('type', 'billing.webhook_rejected')->where('created_at', '>=', $since)->count();
        $unmatched = DB::table('billing_events')->where('created_at', '>=', $since)->where('outcome', 'ignored')
            ->whereIn('note', ['no matching organization', 'customer mismatch', 'second subscription for this organization; cancel it in Stripe'])->count();

        return ['status' => $rejected > 0 || $unmatched > 0 ? 'degraded' : 'ok', 'processed_24h' => (int) ($outcomes['processed'] ?? 0),
            'ignored_24h' => (int) ($outcomes['ignored'] ?? 0), 'unmatched_24h' => $unmatched, 'rejected_24h' => $rejected,
            'last_received_at' => ($t = DB::table('billing_events')->max('created_at')) ? CarbonImmutable::parse($t)->toIso8601String() : null];
    }
}
