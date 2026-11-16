<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Middleware\RequireMfa;
use App\Http\Resources\UserResource;
use App\Models\User;

trait Concerns
{
    protected function sessionPayload(User $user): array
    {
        return [
            'user' => new UserResource($user),
            'mfa_enrollment_required' => $user->requiresMfa() && ! $user->hasMfaEnabled() && ! RequireMfa::viaSso(request()),
            'signed_in_with_sso' => RequireMfa::viaSso(request()),
        ];
    }
}
