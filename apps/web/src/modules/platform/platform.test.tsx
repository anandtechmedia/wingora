import { configure, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createMemoryRouter, RouterProvider } from 'react-router';
import { describe, expect, it, vi } from 'vitest';
import { Permission as P } from '@wingora/permissions';
import { renderUi } from '@/test/render';
import { axeViolations } from '@/test/axe';
import { disconnectedPlatformSource, httpPlatformSource, PlatformSourceProvider, type PlatformSource, type TenantDetail, type TenantRow } from '@/api/platform';
import { SessionProvider } from '@/auth/session';
import type { MeResponse } from '@/auth/types';
import { createMockGateway } from '@/mocks/mock-gateway';
import { personas } from '@/mocks/fixtures';
import { WorkspaceProvider } from '@/shell/workspace';
import { createRoutes } from '@/app/routes';

configure({ asyncUtilTimeout: 5000 });

/** Test doubles shaped like the platform API (backend PlatformTest asserts the real payloads). */
const row: TenantRow = {
  id: 'tn_1', name: 'Acme Corp', slug: 'acme', country_code: 'US', status: 'active', created_at: '2027-01-02T00:00:00Z',
  subscription: { plan: 'growth', status: 'trialing', provider: 'trial', access: 'full', trial_ends_at: '2027-02-01T00:00:00Z' },
};
const detail: TenantDetail = {
  ...row, subscription: null, users: 4, legal_hold: false, deletion_scheduled_for: null, support_access: null,
  usage: { employees: { used: 12, limit: 150 }, ai_requests: { used: 40, limit: 500, period: '2027-01' }, storage_bytes: 1024 },
  subscription_detail: {
    plan: { code: 'growth', name: 'Growth' }, status: 'trialing', access: { mode: 'full', reason: null }, features: [], trial_ends_at: '2027-02-01T00:00:00Z',
    provider: 'trial', billing_interval: null, currency: 'USD', current_period_end: null, cancel_at_period_end: false, version: 7,
  },
};
const page = <T,>(data: T[]) => ({ data, meta: { current_page: 1, last_page: 1, per_page: 25, total: data.length } });
const platformOwner: MeResponse = { ...personas['platform@wingora.test']!, user: { ...personas['platform@wingora.test']!.user, roles: ['platform-owner'] },
  permissions: Object.values(P).filter((p) => p.startsWith('platform.')) };

function fakeSource(overrides: Partial<PlatformSource> = {}): PlatformSource {
  return {
    ...disconnectedPlatformSource,
    tenants: vi.fn(async () => page([row])),
    tenant: vi.fn(async () => detail),
    support: vi.fn(async () => ({ active: false, expires_at: null, include_payroll: false, reason: null, sections: [] })),
    setStatus: vi.fn(async () => ({ ...row, status: 'suspended' as const })),
    changeSubscription: vi.fn(async () => ({})),
    plans: vi.fn(async () => [{ id: 'pl_1', code: 'enterprise', name: 'Enterprise', employee_limit: null, ai_requests_monthly: null, rank: 3, active: true, subscriptions: 1 }]),
    ...overrides,
  };
}

function setup(source: PlatformSource, me: MeResponse = personas['platform@wingora.test']!, path = '/platform') {
  const router = createMemoryRouter(createRoutes(), { initialEntries: [path] });
  return { router, ...renderUi(
    <SessionProvider gateway={createMockGateway(0)} initial={me}>
      <WorkspaceProvider><PlatformSourceProvider value={source}><RouterProvider router={router} /></PlatformSourceProvider></WorkspaceProvider>
    </SessionProvider>,
  ) };
}

const openTenant = async () => {
  await userEvent.click((await screen.findAllByRole('button', { name: /Acme Corp/ }))[0]!);
  return screen.findByRole('dialog', { name: 'Acme Corp' });
};

describe('Platform console', { timeout: 20_000 }, () => {
  it('lands platform staff on the console with no organization switcher, and has no axe violations', async () => {
    const { container, router } = setup(fakeSource(), undefined, '/');
    expect(await screen.findByRole('heading', { level: 1, name: 'Platform console' })).toBeInTheDocument();
    expect(router.state.location.pathname).toBe('/platform');
    expect(screen.queryByRole('button', { name: /Switch organization/i })).not.toBeInTheDocument();
    expect(screen.getByRole('tab', { name: 'Health' })).toBeInTheDocument();
    for (const link of screen.getAllByRole('link', { name: /Wingora|Home/ })) expect(link).toHaveAttribute('href', '/');
    expect(await screen.findAllByText('Acme Corp')).not.toHaveLength(0);
    expect(await axeViolations(container)).toEqual([]);
  });

  it('is denied to organization users, who never see it in navigation', async () => {
    setup(fakeSource(), personas['owner@wingora.test']!);
    expect(await screen.findByRole('heading', { name: /access denied|don.t have access/i })).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'Platform console' })).not.toBeInTheDocument();
  });

  it('suspends an organization only with a reason', async () => {
    const source = fakeSource();
    setup(source);
    const drawer = await openTenant();
    await userEvent.click(await within(drawer).findByRole('button', { name: 'Suspend' }));
    const dialog = await screen.findByRole('alertdialog', { name: 'Suspend Acme Corp?' });
    expect(within(dialog).queryByRole('alert')).not.toBeInTheDocument();
    const confirm = within(dialog).getByRole('button', { name: 'Suspend' });
    expect(confirm).toBeDisabled();
    await userEvent.type(within(dialog).getByLabelText(/Reason/), 'Chargeback under review');
    await userEvent.click(confirm);
    await waitFor(() => expect(source.setStatus).toHaveBeenCalledWith('tn_1', 'suspended', 'Chargeback under review'));
  });

  it('hides subscription changes from platform admins and sends the version for the platform owner', async () => {
    setup(fakeSource());
    const adminDrawer = await openTenant();
    await within(adminDrawer).findByRole('button', { name: 'Suspend' });
    expect(within(adminDrawer).queryByRole('button', { name: 'Assign plan' })).not.toBeInTheDocument();
  });

  it('assigns a plan by hand with the subscription version', async () => {
    const source = fakeSource();
    setup(source, platformOwner);
    const drawer = await openTenant();
    await userEvent.click(await within(drawer).findByRole('button', { name: 'Assign plan' }));
    const dialog = await screen.findByRole('dialog', { name: 'Assign a plan to Acme Corp' });
    await waitFor(() => expect(within(dialog).getByRole('option', { name: 'Enterprise' })).toBeInTheDocument());
    await userEvent.selectOptions(within(dialog).getByLabelText(/^Plan/), 'enterprise');
    await userEvent.type(within(dialog).getByLabelText(/Reason/), 'Signed annual contract');
    await userEvent.click(within(dialog).getByRole('button', { name: 'Save' }));
    await waitFor(() => expect(source.changeSubscription).toHaveBeenCalledWith('tn_1', { action: 'assign_plan', plan: 'enterprise', ends_at: null, reason: 'Signed annual contract', version: 7 }));
  });

  it('shows support data only while the owner has granted access', async () => {
    const overview = { organization: { id: 'tn_1', name: 'Acme Corp', country_code: 'US', status: 'active' }, subscription: null, usage: detail.usage!,
      legal_entities: [], departments: 3, employees_by_status: { active: 12 } };
    const supportSection = vi.fn(async (_id: string, section: string) => (section === 'overview' ? overview
      : page([{ name: 'Dana Ruiz', email: 'dana@acme.test', status: 'active', roles: ['hr-admin'], mfa_enabled: true, last_login_at: null }])));
    const support = vi.fn(async () => ({ active: true, expires_at: '2027-01-10T12:00:00Z', include_payroll: false, reason: 'Import fails', sections: ['overview' as const, 'users' as const] }));
    setup(fakeSource({ support, supportSection: supportSection as unknown as PlatformSource['supportSection'] }));
    const drawer = await openTenant();
    expect(await within(drawer).findByText(/Payroll is not included/)).toBeInTheDocument();
    const select = within(drawer).getByLabelText('Section');
    expect(within(select).queryByRole('option', { name: 'Payroll runs' })).not.toBeInTheDocument();
    await userEvent.selectOptions(select, 'users');
    expect((await within(drawer).findAllByText('dana@acme.test')).length).toBeGreaterThan(0);
    expect(supportSection).toHaveBeenLastCalledWith('tn_1', 'users', 1, expect.anything());
  });

  it('explains that support needs an owner grant when none is active', async () => {
    setup(fakeSource());
    const drawer = await openTenant();
    expect(await within(drawer).findByText(/needs a grant from the organization owner/)).toBeInTheDocument();
    expect(within(drawer).queryByLabelText('Section')).not.toBeInTheDocument();
  });

  it('shows service health per check', async () => {
    const health = vi.fn(async () => ({
      status: 'degraded' as const, checked_at: '2027-01-10T12:00:00Z',
      checks: {
        database: { status: 'ok' as const, latency_ms: 1.2 }, cache: { status: 'ok' as const, latency_ms: 0.4 },
        queue: { status: 'degraded' as const, connection: 'redis', pending: 3, failed: 2, failed_24h: 1 },
        scheduler: { status: 'ok' as const, last_run_at: '2027-01-10T11:59:00Z' },
        webhooks: { status: 'ok' as const, processed_24h: 10, ignored_24h: 0, unmatched_24h: 0, rejected_24h: 0, last_received_at: null },
        data: { status: 'ok' as const, exports_failed_24h: 0, deletions_scheduled: 1, deletions_in_progress: 0 },
      },
    }));
    setup(fakeSource({ health }));
    await userEvent.click(await screen.findByRole('tab', { name: 'Health' }));
    const queue = (await screen.findByRole('heading', { name: 'Job queue' })).closest('section, div')!.parentElement!;
    expect(within(queue as HTMLElement).getByText('Degraded')).toBeInTheDocument();
    expect(screen.getAllByText('Degraded').length).toBeGreaterThanOrEqual(2);
  });
});

describe('Platform HTTP source', () => {
  it('reads plain paginators and keeps the full subscription apart from the list summary', async () => {
    const fetch = vi.fn(async (url: URL) => new Response(JSON.stringify(String(url).includes('/tenants/tn_1')
      ? { data: { ...row, subscription: detail.subscription_detail, users: 4, usage: null, support_access: null, deletion_scheduled_for: null, legal_hold: false } }
      : { data: [row], current_page: 1, last_page: 2, per_page: 25, total: 30 }), { status: 200 }));
    vi.stubGlobal('fetch', fetch);
    try {
      const list = await httpPlatformSource.tenants({ search: 'ac', status: '', plan: '', subscription_status: '' }, 1);
      expect(list.meta).toEqual({ current_page: 1, last_page: 2, per_page: 25, total: 30 });
      expect(String((fetch.mock.calls[0] as unknown[])[0])).toContain('/api/v1/platform/tenants?page=1&search=ac');
      const one = await httpPlatformSource.tenant('tn_1');
      expect(one.subscription_detail?.version).toBe(7);
      expect(one.subscription).toBeNull();
    } finally {
      vi.unstubAllGlobals();
    }
  });
});
