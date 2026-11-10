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
    PP.AttendanceView, PP.LeaveView, PP.RecruitmentView, PP.OnboardingView, PP.OffboardingView, PP.PerformanceView, PP.DocumentsView, PP.ReportsView,
  ], true),
  'employee@wingora.test': persona('usr_employee', 'Jordan Lee', 'employee@wingora.test', ['employee'], [
    P.OrganizationView, P.EmployeesViewSelf, P.FilesUpload, PP.AttendanceView, PP.LeaveView, PP.HelpdeskView, PP.DocumentsView,
  ], false),
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
  { id: 'n2', title: 'Onboarding checklist due', body: 'Jordan Lee starts Monday in Austin', minutesAgo: 95, read: false, moduleId: 'onboarding' },
  { id: 'n3', title: 'Policy document updated', body: 'UAE annual leave policy v2 is ready for review', minutesAgo: 60 * 26, read: true, moduleId: 'documents' },
];
