import { Permission as P, PendingPermission as PP, satisfies, type Requirement } from './permissions.ts';

export type ModuleGroup = 'overview' | 'people' | 'talent' | 'operations' | 'insights' | 'administration';

export type ModuleId =
  | 'dashboard' | 'employees' | 'attendance' | 'leave' | 'payroll' | 'recruitment' | 'onboarding' | 'offboarding'
  | 'performance' | 'assets' | 'helpdesk' | 'projects' | 'reports' | 'documents' | 'settings' | 'billing';

export interface ModuleDefinition {
  id: ModuleId;
  /** Route path segment, relative to the application root. */
  path: string;
  group: ModuleGroup;
  requires: Requirement;
}

export const moduleGroups: readonly ModuleGroup[] = ['overview', 'people', 'talent', 'operations', 'insights', 'administration'];

export const modules: readonly ModuleDefinition[] = [
  { id: 'dashboard', path: 'dashboard', group: 'overview', requires: { anyOf: [P.OrganizationView] } },
  { id: 'employees', path: 'employees', group: 'people', requires: { anyOf: [P.EmployeesViewAll, P.EmployeesViewDepartment, P.EmployeesViewSelf] } },
  { id: 'attendance', path: 'attendance', group: 'people', requires: { anyOf: [P.AttendanceView, P.AttendanceApprove, P.AttendanceManage, P.AttendanceConfigure] } },
  { id: 'leave', path: 'leave', group: 'people', requires: { anyOf: [P.LeaveView, P.LeaveApprove, P.LeaveManage, P.LeaveConfigure] } },
  { id: 'payroll', path: 'payroll', group: 'people', requires: { anyOf: [P.PayrollView, P.PayslipsViewSelf, P.ReimbursementsSubmit, P.ReimbursementsApprove] } },
  { id: 'recruitment', path: 'recruitment', group: 'talent', requires: { anyOf: [P.RecruitmentView, P.RecruitmentInterview] } },
  { id: 'onboarding', path: 'onboarding', group: 'talent', requires: { anyOf: [P.OnboardingView, P.OnboardingTasksSelf] } },
  { id: 'offboarding', path: 'offboarding', group: 'talent', requires: { anyOf: [PP.OffboardingView] } },
  { id: 'performance', path: 'performance', group: 'talent', requires: { anyOf: [PP.PerformanceView] } },
  { id: 'assets', path: 'assets', group: 'operations', requires: { anyOf: [PP.AssetsView] } },
  { id: 'helpdesk', path: 'helpdesk', group: 'operations', requires: { anyOf: [P.HelpdeskSubmit, P.HelpdeskAgent, P.HelpdeskManage, P.HelpdeskConfigure] } },
  { id: 'projects', path: 'projects', group: 'operations', requires: { anyOf: [PP.ProjectsView] } },
  { id: 'documents', path: 'documents', group: 'operations', requires: { anyOf: [PP.DocumentsView] } },
  { id: 'reports', path: 'reports', group: 'insights', requires: { anyOf: [PP.ReportsView, P.AuditView] } },
  { id: 'settings', path: 'settings', group: 'administration', requires: { anyOf: [P.OrganizationManage, P.OrganizationSecurityManage, P.UsersManage, P.RolesAssign] } },
  { id: 'billing', path: 'billing', group: 'administration', requires: { allOf: [PP.BillingView, P.OrganizationManage] } },
];

export function findModule(id: string): ModuleDefinition | undefined {
  return modules.find((m) => m.id === id);
}

export function canAccessModule(granted: ReadonlySet<string> | readonly string[], module: ModuleDefinition): boolean {
  return satisfies(granted, module.requires);
}

/** Modules the user may see, in navigation order, grouped. Empty groups are omitted. */
export function navigationFor(granted: ReadonlySet<string> | readonly string[]): Array<{ group: ModuleGroup; modules: ModuleDefinition[] }> {
  return moduleGroups
    .map((group) => ({ group, modules: modules.filter((m) => m.group === group && canAccessModule(granted, m)) }))
    .filter((g) => g.modules.length > 0);
}
