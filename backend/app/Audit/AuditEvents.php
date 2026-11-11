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

    public const RECRUITMENT_SETTINGS_CHANGED = 'recruitment.settings_changed';

    public const JOB_OPENING_PUBLISHED = 'recruitment.opening_published';

    public const JOB_OPENING_CLOSED = 'recruitment.opening_closed';

    public const APPLICATION_RECEIVED = 'recruitment.application_received';

    public const APPLICATION_MOVED = 'recruitment.application_moved';

    public const APPLICATION_REJECTED = 'recruitment.application_rejected';

    public const APPLICATION_WITHDRAWN = 'recruitment.application_withdrawn';

    public const CANDIDATE_HIRED = 'recruitment.candidate_hired';

    public const CANDIDATE_DOCUMENT_VIEWED = 'candidate.document_viewed';

    public const CANDIDATE_ERASED = 'candidate.erased';

    public const CANDIDATES_RETENTION_PURGED = 'candidate.retention_purged';

    public const INTERVIEW_SCHEDULED = 'recruitment.interview_scheduled';

    public const INTERVIEW_CANCELLED = 'recruitment.interview_cancelled';

    public const INTERVIEW_FEEDBACK_SUBMITTED = 'recruitment.feedback_submitted';

    public const OFFER_CHANGED = 'recruitment.offer_changed';

    public const AI_DRAFT_GENERATED = 'ai.draft_generated';

    public const AI_DRAFT_REVIEWED = 'ai.draft_reviewed';

    public const ONBOARDING_STARTED = 'onboarding.started';

    public const ONBOARDING_TASK_COMPLETED = 'onboarding.task_completed';

    public const ONBOARDING_DOCUMENT_REVIEWED = 'onboarding.document_reviewed';

    public const ONBOARDING_REMINDERS_SENT = 'onboarding.reminders_sent';

    public const HELPDESK_TICKET_CREATED = 'helpdesk.ticket_opened';

    public const HELPDESK_TICKET_ASSIGNED = 'helpdesk.ticket_assigned';

    public const HELPDESK_STATUS_CHANGED = 'helpdesk.ticket_status_changed';

    public const HELPDESK_COMMENT_ADDED = 'helpdesk.comment_added';

    public const HELPDESK_ATTACHMENT_VIEWED = 'helpdesk.attachment_viewed';

    public const HELPDESK_SETTINGS_CHANGED = 'helpdesk.settings_changed';

    public const HELPDESK_ARTICLE_PUBLISHED = 'helpdesk.article_published';

    public const DATA_EXPORTED = 'export.completed';

    public const AUDIT_EXPORTED = 'audit.exported';

    public const AUDIT_RETENTION_CHANGED = 'audit.retention_changed';

    public const AUDIT_RETENTION_PRUNED = 'audit.retention_pruned';

    public const AUDIT_INTEGRITY_VERIFIED = 'audit.integrity_verified';

    public const ACCESS_DENIED = 'authz.denied';
}
