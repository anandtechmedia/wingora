<?php

namespace App\Jobs;

use App\Models\DataExport;
use App\Platform\TenantExporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class BuildTenantExport implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public readonly int $exportId) {}

    public function handle(TenantExporter $exporter): void
    {
        $export = DataExport::query()->withoutGlobalScopes()->find($this->exportId);
        if (! $export || $export->status !== 'queued') {
            return;
        }
        $export->forceFill(['status' => 'running'])->saveQuietly();
        try {
            $exporter->build($export);
        } catch (Throwable $e) {
            $export->forceFill(['status' => 'failed', 'error' => 'The export could not be built.'])->saveQuietly();
            report($e);
        }
    }
}
