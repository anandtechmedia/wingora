<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;

/** Files model-level audit events under performance.* so they land in the performance audit category. */
trait PerformanceAudited
{
    public function auditEvent(string $action): string
    {
        return 'performance.'.Str::snake(preg_replace('/^Performance/', '', class_basename($this))).'_'.$action;
    }
}
