<?php

namespace App\Models;

use App\Models\Concerns\ProjectsAudited;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectMember extends TenantModel
{
    use ProjectsAudited;

    protected $fillable = [];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
