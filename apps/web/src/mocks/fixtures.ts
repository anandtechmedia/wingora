/**
 * PROTOTYPE FIXTURES — visual prototyping only. Not API responses and never shipped as real data.
 * Personas follow the backend role catalog; module permissions the backend lacks yet come from
 * `PendingPermission` so the prototype can show their navigation.
 */
import { Permission as P, PendingPermission as PP } from '@wingora/permissions';
import type { MeResponse } from '@/auth/types';

const tenantPermissions = Object.values(P).filter((p) => !p.startsWith('platform.'));
const organization = { id: 'org_01HZPROTOTYPE', name: 'App Wings LLC', country_code: 'US' };

function persona(id: string, name: string, email: string, roles: string[], permissions: string[], mfa: boolean): MeResponse {
  return {
    user: { id, name, email, status: 'active', roles, mfa_enabled: mfa, last_login_at: null },
    mfa_enrollment_required: false,
    organization,
    permissions,
  };
}

export const personas: Record<string, MeResponse> = {
  'owner@wingora.test': persona('usr_owner', 'Alex Morgan', 'owner@wingora.test', ['organization-owner'], [...tenantPermissions, ...Object.values(PP)], true),
  'hr@wingora.test': persona('usr_hr', 'Priya Nair', 'hr@wingora.test', ['hr-manager'], [
    P.OrganizationView, P.UsersView, P.DepartmentsView, P.EmployeesViewAll, P.EmployeesManage, P.FilesUpload,
    P.EmployeesDisciplinaryView, P.EmployeesDisciplinaryManage, P.EmployeesExport,
    P.AttendanceView, P.AttendanceClock, P.AttendanceApprove, P.AttendanceManage, P.LeaveView, P.LeaveApply, P.LeaveApprove, P.LeaveManage,
    P.RecruitmentView, P.RecruitmentManage, P.RecruitmentInterview, P.OnboardingView, P.OnboardingManage, P.OnboardingTasksSelf, P.HelpdeskSubmit, P.HelpdeskAgent, P.HelpdeskManage, P.DocumentsRequest, P.DocumentsManage, P.AssetsView, P.AssetsManage, P.ProjectsView, P.ProjectsManage, P.TimesheetsApprove, PP.OffboardingView, P.PerformanceView, P.PerformanceReview, P.PerformanceManage, P.PerformanceConfigure, PP.ReportsView,
  ], true),
  'employee@wingora.test': persona('usr_employee', 'Jordan Lee', 'employee@wingora.test', ['employee'], [
    P.OrganizationView, P.EmployeesViewSelf, P.PerformanceView, P.FilesUpload, P.AttendanceView, P.AttendanceClock, P.LeaveView, P.LeaveApply, P.PayslipsViewSelf, P.ReimbursementsSubmit, P.RecruitmentInterview, P.OnboardingTasksSelf, P.HelpdeskSubmit, P.DocumentsRequest, P.AssetsView, P.ProjectsView,
  ], false),
  // Platform staff belong to no organization: they see only the platform console.
  'platform@wingora.test': { ...persona('usr_platform', 'Sam Rivera', 'platform@wingora.test', ['platform-admin'], [
    P.PlatformTenantsView, P.PlatformTenantsManage, P.PlatformAuditView, P.PlatformCountryRulesManage, P.PlatformCountryRulesApprove,
    P.PlatformBillingView, P.PlatformSupportAccess, P.PlatformHealthView,
  ], true), organization: null },
};

export interface OrganizationOption { id: string; name: string; countryCode: string }
export interface LegalEntityOption { id: string; name: string; countryCode: string; currency: string }

export const organizations: OrganizationOption[] = [
  { id: organization.id, name: organization.name, countryCode: 'US' },
  { id: 'org_01HZDEMOGULF', name: 'Gulf Ventures Demo', countryCode: 'AE' },
];

export const legalEntities: Record<string, LegalEntityOption[]> = {
  [organization.id]: [
    { id: 'le_us', name: 'App Wings LLC', countryCode: 'US', currency: 'USD' },
    { id: 'le_ae', name: 'App Wings FZ-LLC', countryCode: 'AE', currency: 'AED' },
    { id: 'le_ca', name: 'App Wings Canada Inc.', countryCode: 'CA', currency: 'CAD' },
    { id: 'le_mx', name: 'App Wings México S. de R.L. de C.V.', countryCode: 'MX', currency: 'MXN' },
  ],
  org_01HZDEMOGULF: [{ id: 'le_gv', name: 'Gulf Ventures FZE', countryCode: 'AE', currency: 'AED' }],
};

export interface NotificationItem { id: string; title: string; body: string; minutesAgo: number; read: boolean; moduleId: string }

export const notifications: NotificationItem[] = [
  { id: 'n1', title: 'Leave request awaiting approval', body: 'Ana Ruiz · 2 days · Guadalajara', minutesAgo: 12, read: false, moduleId: 'leave' },
  { id: 'n2', title: 'Laptop return due Friday', body: 'Asset WG-0142 is due back to IT', minutesAgo: 95, read: false, moduleId: 'assets' },
  { id: 'n3', title: 'Policy document updated', body: 'UAE annual leave policy v2 is ready for review', minutesAgo: 60 * 26, read: true, moduleId: 'documents' },
];
