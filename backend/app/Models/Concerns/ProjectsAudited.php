<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;

/** Files model-level audit events under projects.* so they land in the projects audit category. */
trait ProjectsAudited
{
    public function auditEvent(string $action): string
    {
        return 'projects.'.Str::snake(class_basename($this)).'_'.$action;
    }
}
