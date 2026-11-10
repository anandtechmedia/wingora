<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\TransientToken;
use Symfony\Component\HttpFoundation\Response;

/** Bearer tokens need `api:read` for safe methods and `api:write` for anything else. */
class EnforceTokenAbilities
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        if ($token && ! $token instanceof TransientToken) {
            $ability = $request->isMethodSafe() ? 'api:read' : 'api:write';
            abort_unless($token->can($ability), 403, "This token lacks the {$ability} ability.");
        }

        return $next($request);
    }
}
