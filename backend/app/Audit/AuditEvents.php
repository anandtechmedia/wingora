<?php

namespace App\Audit;

/**
 * Canonical event names. Modules that do not exist yet (leave, attendance, payroll, exports) must use these
 * when they are built so their entries land in the right category and reports.
 */
final class AuditEvents
{
    public const LEAVE_REQUEST_APPROVED = 'leave.request_approved';

    public const LEAVE_REQUEST_REJECTED = 'leave.request_rejected';

    public const ATTENDANCE_ADJUSTED = 'attendance.adjusted';

    public const ATTENDANCE_ADJUSTMENT_APPROVED = 'attendance.adjustment_approved';

    public const PAYROLL_RUN_PREPARED = 'payroll.run_prepared';

    public const PAYROLL_RUN_APPROVED = 'payroll.run_approved';

    public const PAYROLL_RUN_REJECTED = 'payroll.run_rejected';

    public const PAYROLL_RUN_CREATED = 'payroll.run_created';

    public const PAYROLL_RUN_SUBMITTED = 'payroll.run_submitted';

    public const PAYROLL_RUN_PUBLISHED = 'payroll.run_published';

    public const PAYROLL_LINE_ENTERED = 'payroll.line_entered';

    public const PAYROLL_LINE_REMOVED = 'payroll.line_removed';

    public const PAYROLL_PROVIDER_IMPORTED = 'payroll.provider_imported';

    public const PAYROLL_EXPORTED = 'payroll.exported';

    public const PAYSLIP_VIEWED = 'payroll.payslip_viewed';

    public const COMPENSATION_CHANGED = 'payroll.compensation_changed';

    public const LOAN_CREATED = 'payroll.loan_created';

    public const LOAN_CANCELLED = 'payroll.loan_cancelled';

    public const REIMBURSEMENT_SUBMITTED = 'payroll.reimbursement_submitted';

    public const REIMBURSEMENT_DECIDED = 'payroll.reimbursement_decided';

    public const DATA_EXPORTED = 'export.completed';

    public const AUDIT_EXPORTED = 'audit.exported';

    public const AUDIT_RETENTION_CHANGED = 'audit.retention_changed';

    public const AUDIT_RETENTION_PRUNED = 'audit.retention_pruned';

    public const AUDIT_INTEGRITY_VERIFIED = 'audit.integrity_verified';

    public const ACCESS_DENIED = 'authz.denied';
}
