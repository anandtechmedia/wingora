<?php

namespace App\Console\Commands;

use App\Audit\AuditEvents;
use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\Tenant;
use App\Recruitment\CandidateEraser;
use App\Support\Tenancy;
use Illuminate\Console\Command;

/**
 * Applies each tenant's recruitment retention setting: candidates whose every non-hired application is past its
 * retention date are anonymized and their files deleted. Candidates on hold and hired candidates are kept.
 */
class PurgeExpiredCandidates extends Command
{
    protected $signature = 'wingora:recruitment:purge';

    protected $description = 'Anonymize candidates whose retention period has ended';

    public function handle(CandidateEraser $eraser): int
    {
        $total = 0;
        Tenant::query()->where('status', 'active')->each(function (Tenant $tenant) use ($eraser, &$total) {
            Tenancy::run($tenant, function () use ($tenant, $eraser, &$total) {
                $count = 0;
                Candidate::query()->whereNull('anonymized_at')->where('on_hold', false)
                    ->whereHas('applications', fn ($q) => $q->where('status', '!=', 'hired')->where('retain_until', '<', now()))
                    ->whereDoesntHave('applications', fn ($q) => $q->where('status', '!=', 'hired')
                        ->where(fn ($w) => $w->whereNull('retain_until')->orWhere('retain_until', '>=', now())))
                    ->chunkById(100, function ($candidates) use ($eraser, &$count) {
                        foreach ($candidates as $candidate) {
                            $eraser->erase($candidate, 'retention');
                            $count++;
                        }
                    });
                if ($count > 0) {
                    AuditLog::record(AuditEvents::CANDIDATES_RETENTION_PURGED, $tenant, metadata: ['candidates' => $count], tenantId: $tenant->id);
                }
                $total += $count;
            });
        });
        $this->info("Anonymized {$total} candidate(s).");

        return self::SUCCESS;
    }
}
