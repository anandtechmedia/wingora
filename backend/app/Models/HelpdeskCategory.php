<?php

namespace App\Models;

use App\Models\Concerns\HelpdeskAudited;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class HelpdeskCategory extends TenantModel
{
    use HelpdeskAudited;

    protected $fillable = ['name', 'description', 'restricted', 'active', 'sort_order'];

    protected function casts(): array
    {
        return ['restricted' => 'boolean', 'active' => 'boolean', 'sort_order' => 'integer'];
    }

    /** For restricted categories: the only agents who may see or work its tickets. */
    public function agents(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'helpdesk_category_agents')->withPivot('tenant_id');
    }
}
