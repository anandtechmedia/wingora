<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;

/** Files payroll model events under `payroll.*` so they land in the payroll audit category and retention. */
trait PayrollAudited
{
    public function auditEvent(string $action): string
    {
        return 'payroll.'.Str::snake(class_basename($this)).'_'.$action;
    }
}
