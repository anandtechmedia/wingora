<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Every tenant policy first proves the record and the user share a tenant. */
trait Concerns
{
    protected function sameTenant(User $user, Model $model): bool
    {
        return $user->tenant_id !== null && (int) $model->getAttribute('tenant_id') === (int) $user->tenant_id;
    }
}
