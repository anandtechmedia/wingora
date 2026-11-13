<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;

/** Files model-level audit events under helpdesk.* so they land in the helpdesk audit category. */
trait HelpdeskAudited
{
    public function auditEvent(string $action): string
    {
        return 'helpdesk.'.Str::snake(preg_replace('/^Helpdesk/', '', class_basename($this))).'_'.$action;
    }
}
