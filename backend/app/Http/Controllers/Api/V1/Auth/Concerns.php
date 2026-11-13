<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Resources\UserResource;
use App\Models\User;

trait Concerns
{
    protected function sessionPayload(User $user): array
    {
        return [
            'user' => new UserResource($user),
            'mfa_enrollment_required' => $user->requiresMfa() && ! $user->hasMfaEnabled(),
        ];
    }
}
