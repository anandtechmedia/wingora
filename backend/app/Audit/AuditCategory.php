<?php

namespace App\Audit;

use Illuminate\Database\Eloquent\Model;

enum AuditCategory: string
{
    case Authentication = 'authentication';
    case ApiTokens = 'api_tokens';
    case Authorization = 'authorization';
    case Permissions = 'permissions';
    case EmployeeProfile = 'employee_profile';
    case EmployeeDocuments = 'employee_documents';
    case Leave = 'leave';
    case Attendance = 'attendance';
    case Payroll = 'payroll';
    case CountryRules = 'country_rules';
    case Administration = 'administration';
    case DataExport = 'data_export';
    case Recruitment = 'recruitment';
    case Onboarding = 'onboarding';
    case Helpdesk = 'helpdesk';
    case Documents = 'documents';
    case Assets = 'assets';
    case Projects = 'projects';
    case Billing = 'billing';

    public static function forEvent(string $event, ?Model $subject = null): self
    {
        $starts = fn (string ...$prefixes) => array_filter($prefixes, fn ($p) => str_starts_with($event, $p)) !== [];
        $base = $subject ? class_basename($subject) : null;

        return match (true) {
            $starts('auth.token') => self::ApiTokens,
            $starts('auth.') => self::Authentication,
            $starts('authz.denied') => self::Authorization,
            $starts('authz.', 'user.roles', 'role.') => self::Permissions,
            $starts('recruitment.', 'candidate.', 'ai.') => self::Recruitment,
            $starts('onboarding.') => self::Onboarding,
            $starts('helpdesk.') => self::Helpdesk,
            $starts('documents.') => self::Documents,
            $starts('assets.') => self::Assets,
            $starts('projects.') => self::Projects,
            $starts('billing.', 'platform.plan_', 'platform.subscription_') => self::Billing,
            $starts('support.', 'account.') => self::Administration,
            $starts('leave.') => self::Leave,
            $starts('attendance.') => self::Attendance,
            $starts('payroll.', 'compensation.', 'employee_compensation.') => self::Payroll,
            $starts('file.', 'stored_file.') => self::EmployeeDocuments,
            $starts('employee.') => self::EmployeeProfile,
            $starts('country_rules.', 'rule_set.', 'rule_version.') => self::CountryRules,
            $starts('export.', 'audit.exported') => self::DataExport,
            $base === 'Role' => self::Permissions,
            default => self::Administration,
        };
    }
}
