<?php

namespace App\Security;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\SecurityEvent;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/** Prevents privilege escalation: an actor can only grant permissions they already hold. */
class RoleAssigner
{
    /** @param list<string> $roleKeys */
    public function assign(User $actor, User $target, array $roleKeys): void
    {
        $roles = Role::query()->whereIn('key', $roleKeys)->where('scope', 'tenant')->get();

        if ($roles->count() !== count(array_unique($roleKeys))) {
            throw ValidationException::withMessages(['roles' => 'One or more roles are invalid.']);
        }

        $actorPermissions = $actor->permissions();
        foreach ($roles as $role) {
            $exceeds = array_diff(array_map(fn ($p) => $p->value, $role->resolvedPermissions()), array_map(fn ($p) => $p->value, $actorPermissions));
            $ownerOnly = $role->key === RoleCatalog::ORGANIZATION_OWNER && ! $actor->effectiveRoles()->contains('key', RoleCatalog::ORGANIZATION_OWNER);

            if ($exceeds !== [] || $ownerOnly) {
                SecurityEvent::record('authz.role_escalation_blocked', 'warning', $actor, ['role' => $role->key, 'target' => $target->public_id]);
                throw new AuthorizationException('You cannot assign a role with permissions you do not hold.');
            }
        }

        $before = $target->effectiveRoles()->pluck('key')->values()->all();
        $target->roles()->sync($roles->mapWithKeys(fn (Role $r) => [$r->id => ['created_at' => now()]])->all());
        $target->flushPermissions();

        AuditLog::record('user.roles_changed', $target, ['roles' => $before], ['roles' => $roles->pluck('key')->values()->all()]);
        SecurityEvent::record('authz.roles_changed', 'warning', $actor, ['target' => $target->public_id, 'roles' => $roles->pluck('key')->all()]);
    }
}
