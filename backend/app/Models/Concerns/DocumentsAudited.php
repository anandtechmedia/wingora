<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;

/** Files model-level audit events under documents.* so they land in the documents audit category. */
trait DocumentsAudited
{
    public function auditEvent(string $action): string
    {
        return 'documents.'.Str::snake(preg_replace('/^Document/', '', class_basename($this))).'_'.$action;
    }
}
