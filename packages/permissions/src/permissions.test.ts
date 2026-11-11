import { describe, expect, it } from 'vitest';
import { canAccessModule, findModule, homePath, modules, navigationFor, Permission, PendingPermission, satisfies } from './index.ts';

describe('satisfies', () => {
  it('handles anyOf, allOf and authenticated', () => {
    expect(satisfies(['a.view'] as never, { anyOf: ['payroll.view'] })).toBe(false);
    expect(satisfies(['payroll.view'], { anyOf: ['payroll.view', 'audit.view'] })).toBe(true);
    expect(satisfies(new Set(['payroll.view']), { allOf: ['payroll.view', 'payroll.manage'] })).toBe(false);
    expect(satisfies([], 'authenticated')).toBe(true);
  });

  it('fails closed on empty or malformed rules', () => {
    expect(satisfies(['payroll.view'], { anyOf: [] })).toBe(false);
    expect(satisfies(['payroll.view'], { allOf: [] })).toBe(false);
    expect(satisfies(['payroll.view'], {} as never)).toBe(false);
    expect(satisfies(['payroll.view'], null as never)).toBe(false);
  });
});

describe('modules', () => {
  it('defines the 17 navigation modules with unique ids and paths', () => {
    expect(modules).toHaveLength(17);
    expect(new Set(modules.map((m) => m.id)).size).toBe(17);
    expect(new Set(modules.map((m) => m.path)).size).toBe(17);
  });

  it('pending permission values never collide with backend permission values', () => {
    const backend = new Set<string>(Object.values(Permission));
    Object.values(PendingPermission).forEach((p) => expect(backend.has(p)).toBe(false));
  });

  it('shows an Employee only their own modules', () => {
    const employee = [Permission.OrganizationView, Permission.EmployeesViewSelf, Permission.FilesUpload];
    expect(navigationFor(employee).flatMap((g) => g.modules.map((m) => m.id))).toEqual(['dashboard', 'employees']);
    expect(canAccessModule(employee, findModule('payroll')!)).toBe(false);
    expect(canAccessModule(employee, findModule('settings')!)).toBe(false);
  });

  it('a user with no permissions sees nothing', () => {
    expect(navigationFor([])).toEqual([]);
  });

  it('billing needs billing or account management', () => {
    const billing = findModule('billing')!;
    expect(canAccessModule([Permission.OrganizationManage], billing)).toBe(false);
    expect(canAccessModule([Permission.BillingView], billing)).toBe(true);
    expect(canAccessModule([Permission.AccountManage], billing)).toBe(true);
  });

  it('the platform console is only for platform staff, who land on it', () => {
    const platform = findModule('platform')!;
    const tenant = Object.values(Permission).filter((p) => !p.startsWith('platform.'));
    expect(canAccessModule(tenant, platform)).toBe(false);
    expect(canAccessModule([Permission.PlatformHealthView], platform)).toBe(true);
    expect(canAccessModule([Permission.PlatformSupportAccess], platform)).toBe(false);
    expect(homePath([Permission.PlatformTenantsView])).toBe('/platform');
    expect(homePath([Permission.OrganizationView])).toBe('/dashboard');
    expect(homePath([])).toBe('/access-denied');
  });
});

describe('configuration-only access', () => {
  it('opens payroll, recruitment and onboarding for their configure permissions', () => {
    expect(canAccessModule(['payroll.configure'], findModule('payroll')!)).toBe(true);
    expect(canAccessModule(['recruitment.configure'], findModule('recruitment')!)).toBe(true);
    expect(canAccessModule(['onboarding.configure'], findModule('onboarding')!)).toBe(true);
  });
});
