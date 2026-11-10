<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'email' => $this->email,
            'status' => $this->status,
            'roles' => $this->effectiveRoles()->pluck('key')->values(),
            'mfa_enabled' => $this->hasMfaEnabled(),
            'last_login_at' => $this->last_login_at?->toIso8601String(),
        ];
    }
}
