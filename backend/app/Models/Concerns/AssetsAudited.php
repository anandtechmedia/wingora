<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;

/** Files model-level audit events under assets.* so they land in the assets audit category. */
trait AssetsAudited
{
    public function auditEvent(string $action): string
    {
        return 'assets.'.Str::snake(preg_replace('/^Asset/', '', class_basename($this)) ?: 'Asset').'_'.$action;
    }
}
