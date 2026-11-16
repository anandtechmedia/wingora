<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Platform staff, and users of organizations that require it, may only use MFA setup until MFA is confirmed.
 * Single sign-on sessions are exempt: the organization's identity provider is responsible for the second factor.
 */
class RequireMfa
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->requiresMfa() && ! $user->hasMfaEnabled() && ! self::viaSso($request)) {
            return response()->json([
                'type' => 'https://wingora.com/problems/mfa-enrollment-required',
                'title' => 'MFA enrollment required',
                'status' => 403,
                'detail' => 'Your role requires multi-factor authentication. Enrol via /api/v1/auth/mfa/setup.',
            ], 403, ['Content-Type' => 'application/problem+json']);
        }

        return $next($request);
    }

    public static function viaSso(Request $request): bool
    {
        return $request->hasSession() && $request->user() !== null && $request->session()->get('auth.sso') === $request->user()->id;
    }
}
