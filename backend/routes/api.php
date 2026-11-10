<?php

use App\Http\Controllers\Api\V1\Auth\MeController;
use App\Http\Controllers\Api\V1\Auth\MfaController;
use App\Http\Controllers\Api\V1\Auth\PasswordController;
use App\Http\Controllers\Api\V1\Auth\SessionController;
use App\Http\Controllers\Api\V1\Auth\TokenController;
use App\Http\Controllers\Api\V1\DepartmentController;
use App\Http\Controllers\Api\V1\EmployeeController;
use App\Http\Controllers\Api\V1\FileController;
use App\Http\Controllers\Api\V1\LogController;
use App\Http\Controllers\Api\V1\OrganizationController;
use App\Http\Controllers\Api\V1\Platform\TenantController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('v1.')->group(function () {
    // Unauthenticated endpoints, each with its own rate limiter.
    Route::prefix('auth')->group(function () {
        Route::post('login', [SessionController::class, 'store'])->middleware('throttle:login');
        Route::post('login/mfa', [SessionController::class, 'mfa'])->middleware('throttle:mfa');
        Route::post('tokens', [TokenController::class, 'store'])->middleware('throttle:login');
        Route::post('password/forgot', [PasswordController::class, 'forgot'])->middleware('throttle:password-reset');
        Route::post('password/reset', [PasswordController::class, 'reset'])->middleware('throttle:password-reset')->name('password.reset');
    });

    Route::middleware(['auth:sanctum', 'active'])->group(function () {
        // Account self-service: reachable before MFA enrolment so privileged users can enrol.
        Route::prefix('auth')->group(function () {
            Route::get('me', MeController::class);
            Route::post('logout', [SessionController::class, 'destroy']);
            Route::put('password', [PasswordController::class, 'update'])->middleware('throttle:password-reset');
            Route::post('mfa/setup', [MfaController::class, 'setup']);
            Route::post('mfa/confirm', [MfaController::class, 'confirm'])->middleware('throttle:mfa');
            Route::post('mfa/recovery-codes', [MfaController::class, 'recoveryCodes'])->middleware('throttle:mfa');
            Route::delete('mfa', [MfaController::class, 'destroy'])->middleware('throttle:mfa');
            Route::get('tokens', [TokenController::class, 'index']);
            Route::delete('tokens/current', [TokenController::class, 'destroyCurrent']);
            Route::delete('tokens/{tokenId}', [TokenController::class, 'destroy'])->whereNumber('tokenId');
            Route::delete('tokens', [TokenController::class, 'destroyAll']);
        });

        Route::middleware(['mfa', 'abilities.http'])->group(function () {
            // Organization (tenant) data. The tenant always comes from the authenticated user.
            Route::middleware('tenant')->group(function () {
                Route::get('organization', [OrganizationController::class, 'show']);
                Route::patch('organization', [OrganizationController::class, 'update']);
                Route::patch('organization/security', [OrganizationController::class, 'updateSecurity']);

                Route::get('users', [UserController::class, 'index']);
                Route::post('users', [UserController::class, 'store']);
                Route::get('users/{user}', [UserController::class, 'show']);
                Route::put('users/{user}/roles', [UserController::class, 'updateRoles']);
                Route::patch('users/{user}/status', [UserController::class, 'updateStatus']);

                Route::get('departments', [DepartmentController::class, 'index']);
                Route::get('departments/{department}', [DepartmentController::class, 'show']);

                Route::get('employees', [EmployeeController::class, 'index']);
                Route::get('employees/{employee}', [EmployeeController::class, 'show']);
                Route::get('employees/{employee}/sensitive', [EmployeeController::class, 'sensitive']);
                Route::get('employees/{employee}/compensation', [EmployeeController::class, 'compensation']);

                Route::post('files', [FileController::class, 'store'])->middleware('throttle:uploads');
                Route::get('files/{file}', [FileController::class, 'show']);
                Route::get('files/{file}/download', [FileController::class, 'download']);
                Route::delete('files/{file}', [FileController::class, 'destroy']);

                Route::get('audit-logs', [LogController::class, 'audit']);
                Route::get('security-events', [LogController::class, 'security']);
            });

            Route::middleware('platform')->prefix('platform')->group(function () {
                Route::get('tenants', [TenantController::class, 'index']);
                Route::post('tenants', [TenantController::class, 'store']);
                Route::patch('tenants/{tenant}/status', [TenantController::class, 'updateStatus']);
            });
        });
    });
});
