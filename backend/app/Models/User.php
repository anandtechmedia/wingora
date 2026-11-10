<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\TenantScope;
use App\Security\Permission;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use Auditable, BelongsToTenant, HasApiTokens, HasFactory, HasPublicId, Notifiable;

    protected $attributes = ['status' => 'active', 'failed_login_attempts' => 0];

    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = [
        'id', 'tenant_id', 'password', 'remember_token', 'mfa_secret', 'mfa_recovery_codes', 'mfa_last_timestep',
    ];

    /** @var list<Permission>|null */
    private ?array $resolvedPermissions = null;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'mfa_secret' => 'encrypted',
            'mfa_recovery_codes' => 'encrypted:array',
            'mfa_confirmed_at' => 'datetime',
            'locked_until' => 'datetime',
            'last_login_at' => 'datetime',
            'password_changed_at' => 'datetime',
        ];
    }

    /** Platform staff have no tenant. Tenant users are always created inside a tenant context. */
    public function allowsNullTenant(): bool
    {
        return true;
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)->withoutGlobalScope(TenantScope::class)->withPivot('created_at');
    }

    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class);
    }

    /** Roles are only honoured when they belong to the user's own tenant (or platform for staff). */
    public function effectiveRoles()
    {
        return $this->roles->filter(fn (Role $role) => $this->tenant_id === null
            ? $role->scope === 'platform' && $role->tenant_id === null
            : $role->scope === 'tenant' && (int) $role->tenant_id === (int) $this->tenant_id);
    }

    /** @return list<Permission> */
    public function permissions(): array
    {
        return $this->resolvedPermissions ??= array_values(array_unique(
            $this->effectiveRoles()->flatMap(fn (Role $role) => $role->resolvedPermissions())->all(),
            SORT_REGULAR,
        ));
    }

    public function hasPermission(Permission $permission): bool
    {
        return $this->isActive() && in_array($permission, $this->permissions(), true);
    }

    public function flushPermissions(): void
    {
        $this->resolvedPermissions = null;
        $this->unsetRelation('roles');
    }

    public function isPlatformStaff(): bool
    {
        return $this->tenant_id === null && $this->effectiveRoles()->isNotEmpty();
    }

    public function isPrivileged(): bool
    {
        return $this->effectiveRoles()->contains(fn (Role $role) => $role->is_privileged);
    }

    public function requiresMfa(): bool
    {
        return $this->isPrivileged() || (bool) $this->tenant?->mfa_required;
    }

    public function hasMfaEnabled(): bool
    {
        return $this->mfa_confirmed_at !== null && $this->mfa_secret !== null;
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }
}
