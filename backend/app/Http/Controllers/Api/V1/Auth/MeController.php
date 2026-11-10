<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
    use Concerns;

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json($this->sessionPayload($user) + [
            'organization' => $user->tenant ? ['id' => $user->tenant->public_id, 'name' => $user->tenant->name, 'country_code' => $user->tenant->country_code] : null,
            'permissions' => array_map(fn ($p) => $p->value, $user->permissions()),
        ]);
    }
}
