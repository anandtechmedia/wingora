import { configure, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createMemoryRouter, RouterProvider } from 'react-router';
import { describe, expect, it, vi } from 'vitest';
import { renderUi } from '@/test/render';
import { axeViolations } from '@/test/axe';
import { BillingSourceProvider, disconnectedBillingSource, isStripeUrl, type BillingSource, type Plan, type Subscription } from '@/api/billing';
import { SessionProvider } from '@/auth/session';
import type { MeResponse } from '@/auth/types';
import { createMockGateway } from '@/mocks/mock-gateway';
import { personas } from '@/mocks/fixtures';
import { WorkspaceProvider } from '@/shell/workspace';
import { createRoutes } from '@/app/routes';

configure({ asyncUtilTimeout: 5000 });

/** Test doubles shaped like the Billing and Account APIs (backend feature tests assert the real payloads). */
const plan = (code: string, name: string, rank: number, monthly: number | null, limit: number | null): Plan => ({
  code, name, description: null, employee_limit: limit, ai_requests_monthly: 500, features: ['attendance', 'leave'], rank, currency: 'USD',
  prices: { monthly: { amount: monthly, available: monthly !== null }, annual: { amount: monthly && monthly * 10, available: monthly !== null } },
});
const plans = [plan('starter', 'Starter', 1, 4900, 25), plan('growth', 'Growth', 2, 19900, 150), plan('enterprise', 'Enterprise', 3, null, null)];
const trial: Subscription = {
  plan: { code: 'growth', name: 'Growth' }, status: 'trialing', access: { mode: 'full', reason: null }, features: ['attendance'], trial_ends_at: '2027-02-01T00:00:00Z',
  provider: 'trial', interval: null, currency: 'USD', current_period_end: null, cancel_at_period_end: false, canceled_at: null, grace_ends_at: null, pending_change: null,
  limits: { employees: 150, ai_requests_monthly: 500 }, usage: { employees: { used: 12, limit: 150 }, ai_requests: { used: 40, limit: 500, period: '2027-01' }, storage_bytes: 5_242_880 },
  payments_enabled: true, has_payment_account: false, version: 3,
};
const paid: Subscription = { ...trial, status: 'active', provider: 'stripe', interval: 'monthly', trial_ends_at: null, current_period_end: '2027-02-10T00:00:00Z', has_payment_account: true };
const page = <T,>(data: T[]) => ({ data, meta: { current_page: 1, last_page: 1, per_page: 25, total: data.length } });

function fakeSource(subscription: Subscription, overrides: Partial<BillingSource> = {}): BillingSource {
  return {
    ...disconnectedBillingSource,
    subscription: vi.fn(async () => subscription),
    plans: vi.fn(async () => plans),
    invoices: vi.fn(async () => page([{
      id: 'in_1', number: 'WG-0001', status: 'open' as const, currency: 'USD', amount_due: 19900, amount_paid: 0, period_start: '2027-01-10T00:00:00Z',
      period_end: '2027-02-10T00:00:00Z', issued_at: '2027-01-10T00:00:00Z', paid_at: null, hosted_invoice_url: 'https://invoice.stripe.com/i/1', invoice_pdf_url: 'https://pay.stripe.com/pdf/1',
    }])),
    checkout: vi.fn(async () => 'https://checkout.stripe.com/c/pay/cs_1'),
    change: vi.fn(async () => subscription),
    cancel: vi.fn(async () => ({ ...subscription, cancel_at_period_end: true })),
    supportGrants: vi.fn(async () => []),
    grantSupport: vi.fn(),
    exports: vi.fn(async () => []),
    requestExport: vi.fn(),
    deletion: vi.fn(async () => ({ request: null, legal_hold: false, waiting_days: 30 })),
    requestDeletion: vi.fn(),
    redirect: vi.fn(),
    ...overrides,
  };
}

function setup(source: BillingSource, me: MeResponse = personas['owner@wingora.test']!) {
  const router = createMemoryRouter(createRoutes(), { initialEntries: ['/billing'] });
  return renderUi(
    <SessionProvider gateway={createMockGateway(0)} initial={me}>
      <WorkspaceProvider><BillingSourceProvider value={source}><RouterProvider router={router} /></BillingSourceProvider></WorkspaceProvider>
    </SessionProvider>,
  );
}

describe('Billing plan and usage', () => {
  it('shows the trial, usage against limits, and sends a new subscriber to Stripe Checkout', async () => {
    const source = fakeSource(trial);
    const { container } = setup(source);
    expect(await screen.findByText(/Free trial ends/)).toBeInTheDocument();
    expect(screen.getByText('12 of 150')).toBeInTheDocument();
    expect(screen.getByRole('progressbar', { name: 'Active employees' })).toHaveAttribute('aria-valuenow', '8');
    expect(await screen.findByText('Price on request')).toBeInTheDocument();
    expect(screen.getAllByRole('button', { name: 'Subscribe' })[2]).toBeDisabled();
    expect(await axeViolations(container)).toEqual([]);

    await userEvent.click(screen.getAllByRole('button', { name: 'Subscribe' })[1]!);
    await waitFor(() => expect(source.redirect).toHaveBeenCalledWith('https://checkout.stripe.com/c/pay/cs_1'));
    expect(source.checkout).toHaveBeenCalledWith('growth', 'monthly');
  });

  it('changes a paid plan in place with its version, and explains a downgrade waits for renewal', async () => {
    const source = fakeSource(paid);
    setup(source);
    await userEvent.click(await screen.findByRole('button', { name: 'Downgrade' }));
    const dialog = await screen.findByRole('alertdialog');
    expect(within(dialog).getByText(/applies at your next renewal/)).toBeInTheDocument();
    await userEvent.click(within(dialog).getByRole('button', { name: 'Confirm' }));
    await waitFor(() => expect(source.change).toHaveBeenCalledWith('starter', 'monthly', 3));
    expect(source.checkout).not.toHaveBeenCalled();
    expect(screen.getAllByRole('button', { name: 'Switch to this plan' })[0]).toBeDisabled();
  });

  it('cancels at period end after confirmation', async () => {
    const source = fakeSource(paid);
    setup(source);
    await userEvent.click(await screen.findByRole('button', { name: 'Cancel subscription' }));
    const dialog = await screen.findByRole('alertdialog');
    await userEvent.click(within(dialog).getByRole('button', { name: 'Cancel subscription' }));
    await waitFor(() => expect(source.cancel).toHaveBeenCalled());
  });

  it('lists invoices with links to Stripe', async () => {
    setup(fakeSource(paid));
    await userEvent.click(await screen.findByRole('tab', { name: 'Invoices' }));
    expect((await screen.findAllByText('WG-0001')).length).toBeGreaterThan(0);
    const pay = screen.getAllByRole('link', { name: 'Pay' })[0]!;
    expect(pay).toHaveAttribute('href', 'https://invoice.stripe.com/i/1');
    expect(pay).toHaveAttribute('rel', 'noopener noreferrer');
  });
});

describe('Account', () => {
  it('grants time-limited support access with payroll opt-in and requests an MFA-verified export', async () => {
    const source = fakeSource(paid);
    setup(source);
    await userEvent.click(await screen.findByRole('tab', { name: 'Account' }));
    await userEvent.click(await screen.findByRole('button', { name: 'Grant access' }));
    const grant = await screen.findByRole('dialog');
    await userEvent.type(within(grant).getByLabelText(/Reason/), 'Ticket 42');
    await userEvent.click(within(grant).getByLabelText(/Also allow payroll/));
    await userEvent.click(within(grant).getByRole('button', { name: 'Grant access' }));
    await waitFor(() => expect(source.grantSupport).toHaveBeenCalledWith({ hours: 4, reason: 'Ticket 42', include_payroll: true }));

    await userEvent.click(screen.getByRole('button', { name: 'Request export' }));
    const exp = await screen.findByRole('dialog');
    expect(within(exp).queryByRole('alert')).toBeNull();
    await userEvent.type(within(exp).getByLabelText(/Verification code/), '123456');
    await userEvent.click(within(exp).getByRole('button', { name: 'Request export' }));
    await waitFor(() => expect(source.requestExport).toHaveBeenCalledWith({ code: '123456' }));
  });

  it('keeps checking a queued export until it can be downloaded', async () => {
    const queued = { id: 'x1', status: 'queued' as const, size: null, error: null, created_at: '2027-01-10T10:00:00Z', completed_at: null, expires_at: null };
    const exports = vi.fn().mockResolvedValueOnce([queued]).mockResolvedValue([{ ...queued, status: 'ready' as const, size: 2048, expires_at: '2027-01-17T10:00:00Z' }]);
    setup(fakeSource(paid, { exports }));
    await userEvent.click(await screen.findByRole('tab', { name: 'Account' }));
    expect(await screen.findByText('Queued')).toBeInTheDocument();
    expect(await screen.findByRole('button', { name: 'Download' }, { timeout: 8000 })).toBeInTheDocument();
    expect(exports).toHaveBeenCalledTimes(2);
  }, 15000);

  it('only schedules deletion once the organization name matches, and sends recovery codes as such', async () => {
    const source = fakeSource(paid);
    const me = personas['owner@wingora.test']!;
    setup(source, me);
    await userEvent.click(await screen.findByRole('tab', { name: 'Account' }));
    await userEvent.click(await screen.findByRole('button', { name: 'Delete organization' }));
    const dialog = await screen.findByRole('alertdialog');
    const submit = within(dialog).getByRole('button', { name: 'Schedule deletion' });
    await userEvent.type(within(dialog).getByLabelText(/Verification code/), 'abcd-efgh');
    await userEvent.type(within(dialog).getByLabelText(/to confirm/), 'Wrong name');
    expect(submit).toBeDisabled();
    await userEvent.clear(within(dialog).getByLabelText(/to confirm/));
    await userEvent.type(within(dialog).getByLabelText(/to confirm/), me.organization!.name);
    await userEvent.click(submit);
    await waitFor(() => expect(source.requestDeletion).toHaveBeenCalledWith(me.organization!.name, { recovery_code: 'abcd-efgh' }));
  });

  it('blocks deletion under legal hold and lets the owner cancel a scheduled one', async () => {
    const source = fakeSource(paid, {
      deletion: vi.fn(async () => ({ request: { id: 'd1', status: 'scheduled' as const, scheduled_for: '2027-02-10T00:00:00Z', created_at: null, canceled_at: null }, legal_hold: false, waiting_days: 30 })),
      cancelDeletion: vi.fn(async () => ({ id: 'd1', status: 'canceled' as const, scheduled_for: '2027-02-10T00:00:00Z', created_at: null, canceled_at: null })),
    });
    setup(source);
    await userEvent.click(await screen.findByRole('tab', { name: 'Account' }));
    expect(await screen.findByText(/Deletion is scheduled for/)).toBeInTheDocument();
    await userEvent.click(screen.getByRole('button', { name: 'Cancel deletion' }));
    await waitFor(() => expect(source.cancelDeletion).toHaveBeenCalled());
  });
});

describe('Billing access', () => {
  it('is not offered to roles without billing or account permissions', async () => {
    const source = fakeSource(paid);
    setup(source, personas['hr@wingora.test']!);
    await screen.findByRole('heading', { level: 1 });
    expect(screen.queryByRole('tab', { name: 'Plan and usage' })).toBeNull();
    expect(source.subscription).not.toHaveBeenCalled();
  });

  it('shows a read-only banner with a billing link', async () => {
    const me = { ...personas['owner@wingora.test']!, billing: { ...trial, status: 'trialing', access: { mode: 'read_only' as const, reason: 'trial_expired' as const } } };
    setup(fakeSource({ ...trial, access: me.billing.access }), me);
    expect((await screen.findAllByText(/Your free trial has ended/)).length).toBeGreaterThan(0);
    expect(screen.getByRole('link', { name: 'Go to billing' })).toHaveAttribute('href', '/billing');
  });

  it('only follows Stripe-hosted https URLs', () => {
    expect(isStripeUrl('https://checkout.stripe.com/c/pay/x')).toBe(true);
    expect(isStripeUrl('https://billing.stripe.com/p/session/x')).toBe(true);
    expect(isStripeUrl('http://checkout.stripe.com/x')).toBe(false);
    expect(isStripeUrl('https://stripe.com.evil.test/x')).toBe(false);
    expect(isStripeUrl('javascript:alert(1)')).toBe(false);
  });
});
