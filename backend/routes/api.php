<?php

use App\Http\Controllers\Api\V1\AttendanceController;
use App\Http\Controllers\Api\V1\AttendanceRequestController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\AuditRetentionController;
use App\Http\Controllers\Api\V1\Auth\MeController;
use App\Http\Controllers\Api\V1\Auth\MfaController;
use App\Http\Controllers\Api\V1\Auth\PasswordController;
use App\Http\Controllers\Api\V1\Auth\PreferenceController;
use App\Http\Controllers\Api\V1\Auth\SessionController;
use App\Http\Controllers\Api\V1\Auth\TokenController;
use App\Http\Controllers\Api\V1\CompensationController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DepartmentController;
use App\Http\Controllers\Api\V1\DesignationController;
use App\Http\Controllers\Api\V1\Documents\DocumentRequestController;
use App\Http\Controllers\Api\V1\Documents\DocumentTemplateController;
use App\Http\Controllers\Api\V1\EmergencyContactController;
use App\Http\Controllers\Api\V1\EmployeeAssignmentController;
use App\Http\Controllers\Api\V1\EmployeeController;
use App\Http\Controllers\Api\V1\EmployeeDocumentController;
use App\Http\Controllers\Api\V1\EmployeeImporter;
use App\Http\Controllers\Api\V1\EmployeeRecordController;
use App\Http\Controllers\Api\V1\FileController;
use App\Http\Controllers\Api\V1\Helpdesk\HelpdeskSettingsController;
use App\Http\Controllers\Api\V1\Helpdesk\KnowledgeBaseController;
use App\Http\Controllers\Api\V1\Helpdesk\TicketController;
use App\Http\Controllers\Api\V1\HolidayCalendarController;
use App\Http\Controllers\Api\V1\LeaveController;
use App\Http\Controllers\Api\V1\LeaveTypeController;
use App\Http\Controllers\Api\V1\LegalEntityController;
use App\Http\Controllers\Api\V1\LoanController;
use App\Http\Controllers\Api\V1\LogController;
use App\Http\Controllers\Api\V1\Onboarding\OnboardingPlanController;
use App\Http\Controllers\Api\V1\Onboarding\OnboardingTemplateController;
use App\Http\Controllers\Api\V1\OrganizationController;
use App\Http\Controllers\Api\V1\PayrollRunController;
use App\Http\Controllers\Api\V1\PayrollSetupController;
use App\Http\Controllers\Api\V1\PayslipController;
use App\Http\Controllers\Api\V1\Platform\AuditLogController as PlatformAuditLogController;
use App\Http\Controllers\Api\V1\Platform\RuleSetController;
use App\Http\Controllers\Api\V1\Platform\RuleVersionController;
use App\Http\Controllers\Api\V1\Platform\TenantController;
use App\Http\Controllers\Api\V1\PolicyController;
use App\Http\Controllers\Api\V1\Recruitment\AiAssistController;
use App\Http\Controllers\Api\V1\Recruitment\ApplicationController;
use App\Http\Controllers\Api\V1\Recruitment\InterviewController;
use App\Http\Controllers\Api\V1\Recruitment\JobOpeningController;
use App\Http\Controllers\Api\V1\Recruitment\OfferController;
use App\Http\Controllers\Api\V1\Recruitment\PublicCareersController;
use App\Http\Controllers\Api\V1\Recruitment\RecruitmentSettingsController;
use App\Http\Controllers\Api\V1\ReferenceController;
use App\Http\Controllers\Api\V1\ReimbursementController;
use App\Http\Controllers\Api\V1\ShiftController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\WorkLocationController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('v1.')->group(function () {
    // Public careers site: no session, no token; the tenant comes only from an enabled careers slug.
    Route::prefix('public/careers/{site}')->middleware('throttle:careers')->group(function () {
        Route::get('/', [PublicCareersController::class, 'show']);
        Route::get('jobs/{opening}', [PublicCareersController::class, 'opening']);
        Route::post('jobs/{opening}/apply', [PublicCareersController::class, 'apply'])->middleware('throttle:careers-apply');
    });
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
                Route::get('recruitment/settings', [RecruitmentSettingsController::class, 'show']);
                Route::patch('recruitment/settings', [RecruitmentSettingsController::class, 'update']);
                Route::post('recruitment/stages', [RecruitmentSettingsController::class, 'storeStage']);
                Route::patch('recruitment/stages/{stage}', [RecruitmentSettingsController::class, 'updateStage']);
                Route::delete('recruitment/stages/{stage}', [RecruitmentSettingsController::class, 'destroyStage']);
                Route::get('recruitment/openings', [JobOpeningController::class, 'index']);
                Route::post('recruitment/openings', [JobOpeningController::class, 'store']);
                Route::get('recruitment/openings/{opening}', [JobOpeningController::class, 'show']);
                Route::patch('recruitment/openings/{opening}', [JobOpeningController::class, 'update']);
                Route::post('recruitment/openings/{opening}/publish', [JobOpeningController::class, 'publish']);
                Route::post('recruitment/openings/{opening}/close', [JobOpeningController::class, 'close']);
                Route::get('recruitment/board', [ApplicationController::class, 'board']);
                Route::post('recruitment/applications', [ApplicationController::class, 'store'])->middleware('throttle:uploads');
                Route::get('recruitment/applications/{application}', [ApplicationController::class, 'show']);
                Route::post('recruitment/applications/{application}/move', [ApplicationController::class, 'move']);
                Route::post('recruitment/applications/{application}/reject', [ApplicationController::class, 'reject']);
                Route::post('recruitment/applications/{application}/withdraw', [ApplicationController::class, 'withdraw']);
                Route::post('recruitment/applications/{application}/hold', [ApplicationController::class, 'hold']);
                Route::post('recruitment/applications/{application}/erase', [ApplicationController::class, 'erase']);
                Route::post('recruitment/applications/{application}/documents', [ApplicationController::class, 'uploadDocument'])->middleware('throttle:uploads');
                Route::patch('recruitment/documents/{document}', [ApplicationController::class, 'updateDocument']);
                Route::get('recruitment/documents/{document}/download', [ApplicationController::class, 'download']);
                Route::get('recruitment/applications/{application}/offers', [OfferController::class, 'index']);
                Route::post('recruitment/applications/{application}/offers', [OfferController::class, 'store']);
                Route::post('recruitment/applications/{application}/hire', [OfferController::class, 'hire']);
                Route::post('recruitment/offers/{offer}/{action}', [OfferController::class, 'transition'])->whereIn('action', ['approve', 'send', 'accept', 'decline', 'withdraw']);
                Route::get('recruitment/offers/{offer}/letter', [OfferController::class, 'letter']);
                Route::get('recruitment/interviews', [InterviewController::class, 'index']);
                Route::post('recruitment/interviews', [InterviewController::class, 'store']);
                Route::post('recruitment/interviews/{interview}/cancel', [InterviewController::class, 'cancel']);
                Route::post('recruitment/interviews/{interview}/complete', [InterviewController::class, 'complete']);
                Route::post('recruitment/interviews/{interview}/feedback', [InterviewController::class, 'feedback']);
                Route::get('recruitment/applications/{application}/ai-drafts', [AiAssistController::class, 'drafts']);
                Route::post('recruitment/applications/{application}/ai/summary', [AiAssistController::class, 'summary']);
                Route::post('recruitment/applications/{application}/ai/organize-documents', [AiAssistController::class, 'organize']);
                Route::post('recruitment/ai-drafts/{draft}/review', [AiAssistController::class, 'review']);

                Route::get('onboarding/templates', [OnboardingTemplateController::class, 'index']);
                Route::post('onboarding/templates', [OnboardingTemplateController::class, 'store']);
                Route::patch('onboarding/templates/{template}', [OnboardingTemplateController::class, 'update']);
                Route::post('onboarding/templates/{template}/tasks', [OnboardingTemplateController::class, 'storeTask']);
                Route::patch('onboarding/template-tasks/{task}', [OnboardingTemplateController::class, 'updateTask']);
                Route::delete('onboarding/template-tasks/{task}', [OnboardingTemplateController::class, 'destroyTask']);
                Route::get('onboarding/document-requirements', [OnboardingTemplateController::class, 'documentRequirements']);
                Route::get('onboarding/plans', [OnboardingPlanController::class, 'index']);
                Route::post('onboarding/plans', [OnboardingPlanController::class, 'store']);
                Route::get('onboarding/plans/{plan}', [OnboardingPlanController::class, 'show']);
                Route::post('onboarding/plans/{plan}/cancel', [OnboardingPlanController::class, 'cancel']);
                Route::get('onboarding/my-tasks', [OnboardingPlanController::class, 'myTasks']);
                Route::post('onboarding/tasks/{task}/complete', [OnboardingPlanController::class, 'complete']);
                Route::post('onboarding/tasks/{task}/upload', [OnboardingPlanController::class, 'upload'])->middleware('throttle:uploads');
                Route::post('onboarding/tasks/{task}/review', [OnboardingPlanController::class, 'review']);
                Route::post('onboarding/tasks/{task}/skip', [OnboardingPlanController::class, 'skip']);
                Route::get('onboarding/tasks/{task}/document', [OnboardingPlanController::class, 'document']);
                Route::get('helpdesk/settings', [HelpdeskSettingsController::class, 'show']);
                Route::patch('helpdesk/settings', [HelpdeskSettingsController::class, 'update']);
                Route::get('helpdesk/categories', [HelpdeskSettingsController::class, 'categories']);
                Route::post('helpdesk/categories', [HelpdeskSettingsController::class, 'storeCategory']);
                Route::patch('helpdesk/categories/{category}', [HelpdeskSettingsController::class, 'updateCategory']);
                Route::get('helpdesk/agents', [TicketController::class, 'agents']);
                Route::get('helpdesk/tickets', [TicketController::class, 'index']);
                Route::post('helpdesk/tickets', [TicketController::class, 'store'])->middleware('throttle:uploads');
                Route::get('helpdesk/tickets/{ticket}', [TicketController::class, 'show']);
                Route::patch('helpdesk/tickets/{ticket}', [TicketController::class, 'update']);
                Route::post('helpdesk/tickets/{ticket}/assign', [TicketController::class, 'assign']);
                Route::post('helpdesk/tickets/{ticket}/status', [TicketController::class, 'status']);
                Route::post('helpdesk/tickets/{ticket}/comments', [TicketController::class, 'comment'])->middleware('throttle:uploads');
                Route::get('helpdesk/attachments/{attachment}/download', [TicketController::class, 'download']);
                Route::get('helpdesk/articles', [KnowledgeBaseController::class, 'index']);
                Route::post('helpdesk/articles', [KnowledgeBaseController::class, 'store']);
                Route::get('helpdesk/articles/{article}', [KnowledgeBaseController::class, 'show']);
                Route::patch('helpdesk/articles/{article}', [KnowledgeBaseController::class, 'update']);
                Route::delete('helpdesk/articles/{article}', [KnowledgeBaseController::class, 'destroy']);
                Route::post('helpdesk/articles/{article}/publish', [KnowledgeBaseController::class, 'publish']);
                Route::post('helpdesk/articles/{article}/unpublish', [KnowledgeBaseController::class, 'unpublish']);
                Route::get('document-templates', [DocumentTemplateController::class, 'index']);
                Route::post('document-templates', [DocumentTemplateController::class, 'store']);
                Route::patch('document-templates/{documentTemplate}', [DocumentTemplateController::class, 'update']);
                Route::get('document-requests', [DocumentRequestController::class, 'index']);
                Route::post('document-requests', [DocumentRequestController::class, 'store']);
                Route::get('document-requests/{documentRequest}', [DocumentRequestController::class, 'show']);
                Route::post('document-requests/{documentRequest}/decide', [DocumentRequestController::class, 'decide']);
                Route::post('document-requests/{documentRequest}/cancel', [DocumentRequestController::class, 'cancel']);
                Route::post('document-requests/{documentRequest}/deliver', [DocumentRequestController::class, 'deliver'])->middleware('throttle:uploads');
                Route::get('document-requests/{documentRequest}/document', [DocumentRequestController::class, 'download']);

                Route::get('organization', [OrganizationController::class, 'show']);
                Route::patch('organization', [OrganizationController::class, 'update']);
                Route::patch('organization/security', [OrganizationController::class, 'updateSecurity']);

                Route::get('users', [UserController::class, 'index']);
                Route::post('users', [UserController::class, 'store']);
                Route::get('users/{user}', [UserController::class, 'show']);
                Route::put('users/{user}/roles', [UserController::class, 'updateRoles']);
                Route::patch('users/{user}/status', [UserController::class, 'updateStatus']);

                Route::get('dashboard', DashboardController::class);

                Route::get('departments', [DepartmentController::class, 'index']);
                Route::post('departments', [DepartmentController::class, 'store']);
                Route::get('departments/{department}', [DepartmentController::class, 'show']);
                Route::patch('departments/{department}', [DepartmentController::class, 'update']);

                Route::get('designations', [DesignationController::class, 'index']);
                Route::post('designations', [DesignationController::class, 'store']);
                Route::patch('designations/{designation}', [DesignationController::class, 'update']);

                Route::get('employees', [EmployeeController::class, 'index']);
                Route::post('employees', [EmployeeController::class, 'store']);
                Route::get('employees/export', [EmployeeController::class, 'export'])->middleware('throttle:exports');
                Route::get('employees/import', [EmployeeImporter::class, 'template']);
                Route::post('employees/import', EmployeeImporter::class)->middleware('throttle:uploads');
                Route::get('employees/{employee}', [EmployeeController::class, 'show']);
                Route::patch('employees/{employee}', [EmployeeController::class, 'update']);
                Route::get('employees/{employee}/history', [EmployeeController::class, 'history']);
                Route::get('employees/{employee}/personal', [EmployeeController::class, 'personal']);
                Route::patch('employees/{employee}/personal', [EmployeeController::class, 'updatePersonal']);
                Route::get('employees/{employee}/sensitive', [EmployeeController::class, 'sensitive']);
                Route::patch('employees/{employee}/sensitive', [EmployeeController::class, 'updateSensitive']);
                Route::get('employees/{employee}/emergency-contacts', [EmergencyContactController::class, 'index']);
                Route::post('employees/{employee}/emergency-contacts', [EmergencyContactController::class, 'store']);
                Route::patch('employees/{employee}/emergency-contacts/{contact}', [EmergencyContactController::class, 'update']);
                Route::delete('employees/{employee}/emergency-contacts/{contact}', [EmergencyContactController::class, 'destroy']);
                Route::get('employees/{employee}/records', [EmployeeRecordController::class, 'index']);
                Route::post('employees/{employee}/records', [EmployeeRecordController::class, 'store']);
                Route::delete('employees/{employee}/records/{record}', [EmployeeRecordController::class, 'destroy']);
                Route::get('employees/{employee}/documents', [EmployeeDocumentController::class, 'index']);
                Route::get('employees/{employee}/policies', [PolicyController::class, 'forEmployee']);

                Route::get('policies', [PolicyController::class, 'index']);
                Route::post('policies', [PolicyController::class, 'store']);
                Route::post('policies/{policy}/publish', [PolicyController::class, 'publish']);
                Route::post('policies/{policy}/retire', [PolicyController::class, 'retire']);
                Route::post('policies/{policy}/acknowledge', [PolicyController::class, 'acknowledge']);
                Route::get('employees/{employee}/compensation', [EmployeeController::class, 'compensation']);
                Route::put('employees/{employee}/assignment', [EmployeeAssignmentController::class, 'update']);

                Route::get('attendance/me', [AttendanceController::class, 'me']);
                Route::post('attendance/clock-in', [AttendanceController::class, 'clockIn']);
                Route::post('attendance/clock-out', [AttendanceController::class, 'clockOut']);
                Route::get('attendance/records', [AttendanceController::class, 'index']);
                Route::put('attendance/records', [AttendanceController::class, 'correct']);
                Route::get('attendance/report', [AttendanceController::class, 'report'])->middleware('throttle:exports');
                Route::get('attendance/requests', [AttendanceRequestController::class, 'index']);
                Route::post('attendance/requests', [AttendanceRequestController::class, 'store']);
                Route::post('attendance/requests/{attendanceRequest}/approve', [AttendanceRequestController::class, 'approve']);
                Route::post('attendance/requests/{attendanceRequest}/reject', [AttendanceRequestController::class, 'reject']);
                Route::post('attendance/requests/{attendanceRequest}/cancel', [AttendanceRequestController::class, 'cancel']);
                Route::get('shifts', [ShiftController::class, 'shifts']);
                Route::post('shifts', [ShiftController::class, 'storeShift']);
                Route::patch('shifts/{shift}', [ShiftController::class, 'updateShift']);
                Route::get('work-schedules', [ShiftController::class, 'schedules']);
                Route::post('work-schedules', [ShiftController::class, 'storeSchedule']);
                Route::patch('work-schedules/{workSchedule}', [ShiftController::class, 'updateSchedule']);
                Route::get('employees/{employee}/schedule-assignments', [ShiftController::class, 'assignments']);
                Route::post('employees/{employee}/schedule-assignments', [ShiftController::class, 'assign']);

                Route::get('leave-types', [LeaveTypeController::class, 'index']);
                Route::post('leave-types', [LeaveTypeController::class, 'store']);
                Route::patch('leave-types/{leaveType}', [LeaveTypeController::class, 'update']);
                Route::get('leave-types/{leaveType}/policies', [LeaveTypeController::class, 'policies']);
                Route::post('leave-types/{leaveType}/policies', [LeaveTypeController::class, 'storePolicy']);
                Route::post('leave-types/{leaveType}/allocate', [LeaveTypeController::class, 'allocate']);
                Route::post('leave-types/{leaveType}/carry-forward', [LeaveTypeController::class, 'carryForward']);
                Route::get('leave/balances', [LeaveController::class, 'balances']);
                Route::get('leave/ledger', [LeaveController::class, 'ledger']);
                Route::post('leave/adjustments', [LeaveController::class, 'adjust']);
                Route::get('leave/holidays', [LeaveController::class, 'holidays']);
                Route::get('leave/requests', [LeaveController::class, 'index']);
                Route::post('leave/requests/preview', [LeaveController::class, 'preview']);
                Route::post('leave/requests', [LeaveController::class, 'store']);
                Route::get('leave/requests/{leaveRequest}', [LeaveController::class, 'show']);
                Route::post('leave/requests/{leaveRequest}/approve', [LeaveController::class, 'approve']);
                Route::post('leave/requests/{leaveRequest}/reject', [LeaveController::class, 'reject']);
                Route::post('leave/requests/{leaveRequest}/cancel', [LeaveController::class, 'cancel']);

                Route::get('payroll/providers', [PayrollSetupController::class, 'providers']);
                Route::get('payroll/calendars', [PayrollSetupController::class, 'calendars']);
                Route::post('payroll/calendars', [PayrollSetupController::class, 'storeCalendar']);
                Route::patch('payroll/calendars/{calendar}', [PayrollSetupController::class, 'updateCalendar']);
                Route::post('payroll/calendars/{calendar}/periods', [PayrollSetupController::class, 'generatePeriods']);
                Route::get('payroll/periods', [PayrollSetupController::class, 'periods']);
                Route::get('payroll/structures', [PayrollSetupController::class, 'structures']);
                Route::post('payroll/structures', [PayrollSetupController::class, 'storeStructure']);
                Route::patch('payroll/structures/{structure}', [PayrollSetupController::class, 'updateStructure']);
                Route::post('payroll/structures/{structure}/components', [PayrollSetupController::class, 'storeComponent']);
                Route::post('payroll/structures/{structure}/components/{component}/end', [PayrollSetupController::class, 'endComponent']);
                Route::get('payroll/compensation', [CompensationController::class, 'index']);
                Route::get('employees/{employee}/compensation-history', [CompensationController::class, 'history']);
                Route::post('employees/{employee}/compensation-history', [CompensationController::class, 'store']);
                Route::get('payroll/runs', [PayrollRunController::class, 'index']);
                Route::post('payroll/runs', [PayrollRunController::class, 'store']);
                Route::get('payroll/runs/{run}', [PayrollRunController::class, 'show']);
                Route::get('payroll/runs/{run}/payslips', [PayrollRunController::class, 'payslips']);
                Route::post('payroll/runs/{run}/prepare', [PayrollRunController::class, 'prepare']);
                Route::post('payroll/runs/{run}/payslips/{payslip}/lines', [PayrollRunController::class, 'storeLine']);
                Route::delete('payroll/runs/{run}/lines/{line}', [PayrollRunController::class, 'destroyLine']);
                Route::post('payroll/runs/{run}/provider-import', [PayrollRunController::class, 'importProvider'])->middleware('throttle:uploads');
                Route::post('payroll/runs/{run}/submit', [PayrollRunController::class, 'submit']);
                Route::post('payroll/runs/{run}/approve', [PayrollRunController::class, 'approve']);
                Route::post('payroll/runs/{run}/reject', [PayrollRunController::class, 'reject']);
                Route::post('payroll/runs/{run}/publish', [PayrollRunController::class, 'publish']);
                Route::get('payroll/runs/{run}/export', [PayrollRunController::class, 'export'])->middleware('throttle:exports');
                Route::get('payroll/payslips/mine', [PayslipController::class, 'mine']);
                Route::get('payroll/payslips/{payslip}', [PayslipController::class, 'show']);
                Route::get('payroll/payslips/{payslip}/pdf', [PayslipController::class, 'pdf'])->middleware('throttle:exports');
                Route::get('payroll/loans', [LoanController::class, 'index']);
                Route::post('payroll/loans', [LoanController::class, 'store']);
                Route::post('payroll/loans/{loan}/cancel', [LoanController::class, 'cancel']);
                Route::get('reimbursements', [ReimbursementController::class, 'index']);
                Route::post('reimbursements', [ReimbursementController::class, 'store']);
                Route::post('reimbursements/{reimbursement}/approve', [ReimbursementController::class, 'approve']);
                Route::post('reimbursements/{reimbursement}/reject', [ReimbursementController::class, 'reject']);
                Route::post('reimbursements/{reimbursement}/cancel', [ReimbursementController::class, 'cancel']);
                Route::get('reimbursements/{reimbursement}/receipt', [ReimbursementController::class, 'receipt']);

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
