<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;

/** Files model-level audit events under billing.* so they land in the billing audit category. */
trait BillingAudited
{
    public function auditEvent(string $action): string
    {
        return 'billing.'.Str::snake(class_basename($this)).'_'.$action;
    }
}
