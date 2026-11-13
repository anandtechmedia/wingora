<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlatformStaff
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->isPlatformStaff(), 403, 'This action requires a platform account.');

        return $next($request);
    }
}
