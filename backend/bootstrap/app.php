<?php

use App\Exceptions\TenantContextMissing;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnforceTokenAbilities;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsurePlatformStaff;
use App\Http\Middleware\ForceJson;
use App\Http\Middleware\RequireMfa;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use App\Models\SecurityEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Context;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(AssignRequestId::class);
        $middleware->append(SecurityHeaders::class);
        $middleware->api(prepend: [ForceJson::class], append: [SetLocale::class]);
        $middleware->statefulApi();
        $middleware->throttleApi('api');

        $proxies = array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', ''))));
        $middleware->trustProxies(at: $proxies ?: null);
        $middleware->trustHosts(at: fn () => config('security.trusted_hosts') ?: null);

        $middleware->encryptCookies();
        $middleware->redirectGuestsTo(fn () => null);

        $middleware->alias([
            'active' => EnsureAccountIsActive::class,
            'mfa' => RequireMfa::class,
            'tenant' => ResolveTenant::class,
            'platform' => EnsurePlatformStaff::class,
            'abilities.http' => EnforceTokenAbilities::class,
        ]);

        // Tenant context must exist before route-model binding runs, so bound models are tenant-scoped.
        foreach ([EnsureAccountIsActive::class, RequireMfa::class, EnforceTokenAbilities::class, ResolveTenant::class, EnsurePlatformStaff::class, SetLocale::class] as $class) {
            $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: $class);
        }
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());

        $exceptions->dontFlash(['password', 'password_confirmation', 'current_password', 'code', 'recovery_code', 'token']);

        $exceptions->report(function (TenantContextMissing $e) {
            SecurityEvent::record('tenancy.context_missing', 'critical', null, ['message' => $e->getMessage()]);
        });

        $exceptions->render(function (Throwable $e, Request $request) {
            if (! ($request->is('api/*') || $request->expectsJson())) {
                return null;
            }

            [$status, $title, $detail, $extra] = match (true) {
                $e instanceof ValidationException => [422, 'Validation failed', 'The given data was invalid.', ['errors' => $e->errors()]],
                $e instanceof AuthenticationException => [401, 'Unauthenticated', 'Authentication is required.', []],
                $e instanceof AuthorizationException => [403, 'Forbidden', 'You are not allowed to perform this action.', []],
                // Covers route-model binding misses without leaking model class names in `detail`.
                $e instanceof ModelNotFoundException, $e instanceof NotFoundHttpException => [404, 'Not found', 'The requested resource was not found.', []],
                $e instanceof TokenMismatchException => [419, 'CSRF token mismatch', 'Refresh the CSRF cookie and retry.', []],
                $e instanceof ThrottleRequestsException => [429, 'Too many requests', 'Slow down and retry later.', []],
                $e instanceof HttpExceptionInterface => [$e->getStatusCode(), Response::$statusTexts[$e->getStatusCode()] ?? 'Error', $e->getStatusCode() < 500 && $e->getMessage() !== '' ? $e->getMessage() : null, []],
                default => [500, 'Server error', null, []],
            };

            $body = array_filter([
                'type' => 'about:blank',
                'title' => __($title),
                'status' => $status,
                'detail' => $detail === null ? null : __($detail),
                'request_id' => Context::get('request_id'),
            ], fn ($v) => $v !== null) + $extra;

            if ($status === 500 && config('app.debug')) {
                $body['debug'] = ['exception' => $e::class, 'message' => $e->getMessage()];
            }

            $headers = ['Content-Type' => 'application/problem+json'];
            if ($e instanceof HttpExceptionInterface) {
                $headers += array_intersect_key($e->getHeaders(), array_flip(['Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining']));
            }

            return response()->json($body, $status, $headers);
        });
    })->create();
