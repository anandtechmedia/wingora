<?php

namespace App\Http\Middleware;

use App\Support\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The tenant is always the authenticated user's own tenant. It is never taken
 * from a header, subdomain or request parameter, so it cannot be spoofed.
 */
class ResolveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_if(! $user || $user->tenant_id === null || ! $user->tenant, 403, 'This action requires an organization account.');

        Tenancy::set($user->tenant);

        try {
            return $next($request);
        } finally {
            Tenancy::forget();
        }
    }
}
