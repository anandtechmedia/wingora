<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;

/** Files model-level audit events under recruitment.* or onboarding.* so they land in the right audit category. */
trait RecruitmentAudited
{
    public function auditEvent(string $action): string
    {
        $prefix = str_starts_with(class_basename($this), 'Onboarding') ? 'onboarding' : 'recruitment';

        return $prefix.'.'.Str::snake(class_basename($this)).'_'.$action;
    }
}
