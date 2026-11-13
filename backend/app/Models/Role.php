<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Security\Permission;
use App\Security\RoleCatalog;
use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    use BelongsToTenant;

    protected $fillable = [];

    protected $hidden = ['id', 'tenant_id', 'pivot'];

    protected function casts(): array
    {
        return ['permissions' => 'array', 'is_system' => 'boolean', 'is_privileged' => 'boolean'];
    }

    public function allowsNullTenant(): bool
    {
        return $this->scope === 'platform';
    }

    /** @return list<Permission> */
    public function resolvedPermissions(): array
    {
        $permissions = $this->is_system
            ? RoleCatalog::permissionsFor($this->key)
            : array_filter(array_map(fn ($p) => Permission::tryFrom((string) $p), $this->permissions ?? []));

        // A role can never carry permissions outside its own scope.
        return array_values(array_filter($permissions, fn (Permission $p) => $p->isPlatform() === ($this->scope === 'platform')));
    }
}
