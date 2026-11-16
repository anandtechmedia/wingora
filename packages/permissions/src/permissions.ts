/**
 * Permission keys. Values must match the backend `App\Security\Permission` enum exactly.
 * The server is the only enforcement point; the client uses these to hide what a user cannot use.
 */
export const Permission = {
  PlatformTenantsView: 'platform.tenants.view',
  PlatformTenantsManage: 'platform.tenants.manage',
  PlatformStaffManage: 'platform.staff.manage',
  PlatformAuditView: 'platform.audit.view',
  PlatformAuditExport: 'platform.audit.export',
  PlatformCountryRulesManage: 'platform.country-rules.manage',
  PlatformCountryRulesApprove: 'platform.country-rules.approve',
  PlatformBillingView: 'platform.billing.view',
  PlatformBillingManage: 'platform.billing.manage',
  PlatformSupportAccess: 'platform.support.access',
  PlatformHealthView: 'platform.health.view',
  OrganizationView: 'organization.view',
  OrganizationManage: 'organization.manage',
  OrganizationSecurityManage: 'organization.security.manage',
  LegalEntitiesManage: 'organization.entities.manage',
  UsersView: 'users.view',
  UsersManage: 'users.manage',
  RolesAssign: 'roles.assign',
  DepartmentsView: 'departments.view',
  DepartmentsManage: 'departments.manage',
  EmployeesViewAll: 'employees.view.all',
  EmployeesViewDepartment: 'employees.view.department',
  EmployeesViewSelf: 'employees.view.self',
  EmployeesManage: 'employees.manage',
  EmployeesSensitiveView: 'employees.sensitive.view',
  EmployeesDisciplinaryView: 'employees.disciplinary.view',
  EmployeesDisciplinaryManage: 'employees.disciplinary.manage',
  EmployeesImport: 'employees.import',
  EmployeesExport: 'employees.export',
  PoliciesManage: 'policies.manage',
  AttendanceView: 'attendance.view',
  AttendanceClock: 'attendance.clock',
  AttendanceApprove: 'attendance.approve',
  AttendanceManage: 'attendance.manage',
  AttendanceConfigure: 'attendance.configure',
  LeaveView: 'leave.view',
  LeaveApply: 'leave.apply',
  LeaveApprove: 'leave.approve',
  LeaveManage: 'leave.manage',
  LeaveConfigure: 'leave.configure',
  PayrollView: 'payroll.view',
  PayrollManage: 'payroll.manage',
  PayrollConfigure: 'payroll.configure',
  PayrollApprove: 'payroll.approve',
  PayrollExport: 'payroll.export',
  PayslipsViewSelf: 'payslips.view.self',
  ReimbursementsSubmit: 'reimbursements.submit',
  ReimbursementsApprove: 'reimbursements.approve',
  RecruitmentView: 'recruitment.view',
  RecruitmentManage: 'recruitment.manage',
  RecruitmentConfigure: 'recruitment.configure',
  RecruitmentOffers: 'recruitment.offers',
  RecruitmentInterview: 'recruitment.interview',
  OnboardingView: 'onboarding.view',
  OnboardingManage: 'onboarding.manage',
  OnboardingConfigure: 'onboarding.configure',
  OnboardingTasksSelf: 'onboarding.tasks.self',
  HelpdeskSubmit: 'helpdesk.submit',
  HelpdeskAgent: 'helpdesk.agent',
  HelpdeskManage: 'helpdesk.manage',
  HelpdeskConfigure: 'helpdesk.configure',
  DocumentsRequest: 'documents.request',
  DocumentsApprove: 'documents.approve',
  DocumentsManage: 'documents.manage',
  DocumentsSalary: 'documents.salary',
  DocumentsConfigure: 'documents.configure',
  AssetsView: 'assets.view',
  AssetsManage: 'assets.manage',
  ProjectsView: 'projects.view',
  ProjectsManage: 'projects.manage',
  TimesheetsApprove: 'timesheets.approve',
  PerformanceView: 'performance.view',
  PerformanceReview: 'performance.review',
  PerformanceManage: 'performance.manage',
  PerformanceConfigure: 'performance.configure',
  BillingView: 'billing.view',
  BillingManage: 'billing.manage',
  AccountManage: 'account.manage',
  FilesUpload: 'files.upload',
  AuditView: 'audit.view',
  SecurityEventsView: 'security-events.view',
} as const;

/**
 * Module permissions the backend does not define yet. Each must be added to the backend enum and
 * role catalog, with the same value, when its module is built. Until then no real user holds them.
 */
export const PendingPermission = {
  OffboardingView: 'offboarding.view',
  ReportsView: 'reports.view',
} as const;

export type PermissionKey = (typeof Permission)[keyof typeof Permission] | (typeof PendingPermission)[keyof typeof PendingPermission];

/** Access rule: `anyOf` grants with at least one permission, `allOf` needs every one; `authenticated` needs none. */
export type Requirement = 'authenticated' | { anyOf: readonly PermissionKey[] } | { allOf: readonly PermissionKey[] };

/** Unknown or malformed rules deny access (fail closed). */
export function satisfies(granted: ReadonlySet<string> | readonly string[], requirement: Requirement): boolean {
  const has = (p: string) => (Array.isArray(granted) ? granted.includes(p) : (granted as ReadonlySet<string>).has(p));
  if (requirement === 'authenticated') return true;
  if (typeof requirement !== 'object' || requirement === null) return false;
  if ('anyOf' in requirement) return requirement.anyOf.length > 0 && requirement.anyOf.some(has);
  if ('allOf' in requirement) return requirement.allOf.length > 0 && requirement.allOf.every(has);
  return false;
}
