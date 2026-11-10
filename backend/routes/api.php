<?php

use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\AuditRetentionController;
use App\Http\Controllers\Api\V1\Auth\MeController;
use App\Http\Controllers\Api\V1\Auth\MfaController;
use App\Http\Controllers\Api\V1\Auth\PasswordController;
use App\Http\Controllers\Api\V1\Auth\PreferenceController;
use App\Http\Controllers\Api\V1\Auth\SessionController;
use App\Http\Controllers\Api\V1\Auth\TokenController;
use App\Http\Controllers\Api\V1\DepartmentController;
use App\Http\Controllers\Api\V1\EmployeeAssignmentController;
use App\Http\Controllers\Api\V1\EmployeeController;
use App\Http\Controllers\Api\V1\FileController;
use App\Http\Controllers\Api\V1\HolidayCalendarController;
use App\Http\Controllers\Api\V1\LegalEntityController;
use App\Http\Controllers\Api\V1\LogController;
use App\Http\Controllers\Api\V1\OrganizationController;
use App\Http\Controllers\Api\V1\Platform\AuditLogController as PlatformAuditLogController;
use App\Http\Controllers\Api\V1\Platform\RuleSetController;
use App\Http\Controllers\Api\V1\Platform\RuleVersionController;
use App\Http\Controllers\Api\V1\Platform\TenantController;
use App\Http\Controllers\Api\V1\ReferenceController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\WorkLocationController;
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
            Route::patch('preferences', [PreferenceController::class, 'update']);
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
            // Global reference data and approved country rules (not tenant-owned).
            Route::prefix('reference')->group(function () {
                Route::get('countries', [ReferenceController::class, 'countries']);
                Route::get('countries/{country}', [ReferenceController::class, 'showCountry']);
                Route::get('countries/{country}/rules', [ReferenceController::class, 'rules']);
                Route::get('currencies', [ReferenceController::class, 'currencies']);
                Route::get('languages', [ReferenceController::class, 'languages']);
                Route::get('locales', [ReferenceController::class, 'locales']);
            });

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
                Route::put('employees/{employee}/assignment', [EmployeeAssignmentController::class, 'update']);

                Route::get('legal-entities', [LegalEntityController::class, 'index']);
                Route::post('legal-entities', [LegalEntityController::class, 'store']);
                Route::get('legal-entities/{legalEntity}', [LegalEntityController::class, 'show']);
                Route::patch('legal-entities/{legalEntity}', [LegalEntityController::class, 'update']);

                Route::get('work-locations', [WorkLocationController::class, 'index']);
                Route::post('work-locations', [WorkLocationController::class, 'store']);
                Route::get('work-locations/{workLocation}', [WorkLocationController::class, 'show']);
                Route::patch('work-locations/{workLocation}', [WorkLocationController::class, 'update']);
                Route::get('work-locations/{workLocation}/rules', [WorkLocationController::class, 'rules']);
                Route::get('work-locations/{workLocation}/holidays', [WorkLocationController::class, 'holidays']);

                Route::get('holiday-calendars', [HolidayCalendarController::class, 'index']);
                Route::post('holiday-calendars', [HolidayCalendarController::class, 'store']);
                Route::get('holiday-calendars/{holidayCalendar}', [HolidayCalendarController::class, 'show']);
                Route::patch('holiday-calendars/{holidayCalendar}', [HolidayCalendarController::class, 'update']);
                Route::post('holiday-calendars/{holidayCalendar}/holidays', [HolidayCalendarController::class, 'storeHoliday']);
                Route::delete('holidays/{holiday}', [HolidayCalendarController::class, 'destroyHoliday']);

                Route::post('files', [FileController::class, 'store'])->middleware('throttle:uploads');
                Route::get('files/{file}', [FileController::class, 'show']);
                Route::get('files/{file}/download', [FileController::class, 'download']);
                Route::delete('files/{file}', [FileController::class, 'destroy']);

                Route::get('audit-logs', [AuditLogController::class, 'index']);
                Route::get('audit-logs/summary', [AuditLogController::class, 'summary']);
                Route::get('audit-logs/export', [AuditLogController::class, 'export'])->middleware('throttle:exports');
                Route::get('audit-logs/integrity', [AuditLogController::class, 'integrity'])->middleware('throttle:exports');
                Route::get('audit-logs/{auditLog}', [AuditLogController::class, 'show']);
                Route::get('audit-retention', [AuditRetentionController::class, 'show']);
                Route::put('audit-retention', [AuditRetentionController::class, 'update']);
                Route::get('security-events', [LogController::class, 'security']);
            });

            Route::middleware('platform')->prefix('platform')->group(function () {
                Route::get('tenants', [TenantController::class, 'index']);
                Route::post('tenants', [TenantController::class, 'store']);
                Route::patch('tenants/{tenant}/status', [TenantController::class, 'updateStatus']);

                Route::get('audit-logs', [PlatformAuditLogController::class, 'index']);
                Route::get('audit-logs/summary', [PlatformAuditLogController::class, 'summary']);
                Route::get('audit-logs/export', [PlatformAuditLogController::class, 'export'])->middleware('throttle:exports');
                Route::get('audit-logs/integrity', [PlatformAuditLogController::class, 'integrity'])->middleware('throttle:exports');

                Route::get('rule-sets', [RuleSetController::class, 'index']);
                Route::post('rule-sets', [RuleSetController::class, 'store']);
                Route::get('rule-sets/{ruleSet}', [RuleSetController::class, 'show']);
                Route::post('rule-sets/{ruleSet}/versions', [RuleSetController::class, 'storeVersion']);
                Route::get('rule-versions/{ruleVersion}', [RuleVersionController::class, 'show']);
                Route::put('rule-versions/{ruleVersion}', [RuleVersionController::class, 'update']);
                Route::post('rule-versions/{ruleVersion}/submit', [RuleVersionController::class, 'submit']);
                Route::post('rule-versions/{ruleVersion}/approve', [RuleVersionController::class, 'approve']);
                Route::post('rule-versions/{ruleVersion}/reject', [RuleVersionController::class, 'reject']);
                Route::post('rule-versions/{ruleVersion}/retire', [RuleVersionController::class, 'retire']);
            });
        });
    });
});
