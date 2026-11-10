import { configure, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createMemoryRouter, RouterProvider } from 'react-router';
import { describe, expect, it, vi } from 'vitest';
import { renderUi } from '@/test/render';
import { axeViolations } from '@/test/axe';
import { disconnectedPayrollSource, PayrollSourceProvider, type MyPayslip, type PayrollRunDetail, type PayrollSource, type Payslip } from '@/api/payroll';
import { SessionProvider } from '@/auth/session';
import { createMockGateway } from '@/mocks/mock-gateway';
import { personas } from '@/mocks/fixtures';
import { WorkspaceProvider } from '@/shell/workspace';
import { createRoutes } from '@/app/routes';

configure({ asyncUtilTimeout: 5000 });

/** Test doubles shaped like the Payroll API (backend feature tests assert the real payloads). */
const usd = (amount: string) => ({ amount, minor: Math.round(Number(amount) * 100), currency: 'USD' });
const page = <T,>(data: T[]) => ({ data, meta: { current_page: 1, last_page: 1, per_page: 25, total: data.length } });
const run: PayrollRunDetail = {
  id: 'run1', type: 'regular', status: 'in_review', currency: 'USD', legal_entity: { id: 'le1', name: 'App Wings US', country_code: 'US' },
  period: { id: 'p1', start_date: '2027-01-01', end_date: '2027-01-31', pay_date: '2027-02-03', pay_frequency: 'monthly', calendar: 'US monthly' },
  approval_levels: 2, current_level: 2, review_cycle: 1, employee_count: 1, gross: usd('5500.00'), deductions: usd('632.50'), net: usd('4867.50'),
  notes: null, rejection_note: null, prepared_at: '2027-01-25T10:00:00Z', submitted_at: '2027-01-25T11:00:00Z', approved_at: null, published_at: null, disclaimer: '',
  can: { edit: false, submit: false, approve: true, publish: false, export: true }, prepared_by: 'Pat Preparer', submitted_by: 'Pat Preparer', payslips_with_issues: 0,
  totals_by_source: [{ source: 'calculated', kind: 'earning', lines: 2, total: usd('5500.00') }, { source: 'manual', kind: 'deduction', lines: 1, total: usd('382.50') }],
  approvals: [{ review_cycle: 1, level: 1, decision: 'approved', by: 'Ana Approver', note: null, decided_at: '2027-01-26T09:00:00Z' }],
};
const slip: Payslip = {
  id: 'ps1', employee: { name: 'Jordan Lee', employee_number: 'E-200' }, currency: 'USD', days_paid: 31, period_days: 31, gross: usd('5500.00'), deductions: usd('632.50'), net: usd('4867.50'), issues: [],
  lines: [
    { id: 'l1', code: 'BASE', name: 'Base salary', kind: 'earning', source: 'calculated', amount: usd('5000.00'), basis: null, reason: null, provider: null, provider_reference: null },
    { id: 'l2', code: 'FICA', name: 'FICA', kind: 'deduction', source: 'manual', amount: usd('382.50'), basis: null, reason: 'From provider worksheet', provider: null, provider_reference: null },
    { id: 'l3', code: 'FIT', name: 'Federal income tax', kind: 'deduction', source: 'provider', amount: usd('250.00'), basis: null, reason: null, provider: 'generic_csv', provider_reference: 'PRV-1' },
  ],
};
const mine: MyPayslip = { ...slip, lines: undefined, period: { start_date: '2027-01-01', end_date: '2027-01-31', pay_date: '2027-02-03' }, run_type: 'regular' };

function fakeSource(overrides: Partial<PayrollSource> = {}): PayrollSource {
  return {
    ...disconnectedPayrollSource,
    myPayslips: vi.fn(async () => page([mine])),
    reimbursements: vi.fn(async () => page([])),
    loans: vi.fn(async () => page([])),
    runs: vi.fn(async () => page([run])),
    run: vi.fn(async () => run),
    payslips: vi.fn(async () => page([slip])),
    decide: vi.fn(async () => ({ ...run, status: 'approved' as const })),
    downloadPayslip: vi.fn(async () => undefined),
    ...overrides,
  };
}

function setup(source: PayrollSource, persona: keyof typeof personas) {
  const router = createMemoryRouter(createRoutes(), { initialEntries: ['/payroll'] });
  return renderUi(
    <SessionProvider gateway={createMockGateway(0)} initial={personas[persona]!}>
      <WorkspaceProvider><PayrollSourceProvider value={source}><RouterProvider router={router} /></PayrollSourceProvider></WorkspaceProvider>
    </SessionProvider>,
  );
}

describe('payroll', { timeout: 20_000 }, () => {
  it('shows an employee only their own pay and downloads a published payslip', async () => {
    const source = fakeSource();
    setup(source, 'employee@wingora.test');
    const row = (await screen.findAllByText('2027-01-01 – 2027-01-31')).map((e) => e.closest('tr')).find(Boolean)!;
    expect(within(row).getByText('$4,867.50')).toBeInTheDocument();
    expect(screen.queryByRole('tab', { name: 'Payroll runs' })).not.toBeInTheDocument();
    expect(screen.queryByRole('tab', { name: 'Setup' })).not.toBeInTheDocument();
    await userEvent.click(within(row).getByRole('button', { name: 'Download PDF' }));
    expect(source.downloadPayslip).toHaveBeenCalledWith('ps1');
    expect(source.runs).not.toHaveBeenCalled();
  });

  it('labels every line by source and approves the next level', async () => {
    const source = fakeSource();
    const { container } = setup(source, 'owner@wingora.test');
    await userEvent.click(await screen.findByRole('tab', { name: 'Payroll runs' }));
    await userEvent.click((await screen.findAllByRole('button', { name: 'Open' }))[0]!);
    expect(await screen.findByText(/Not a statutory payroll calculation/)).toBeInTheDocument();
    expect(screen.getByText(/Level 1 approved by Ana Approver/)).toBeInTheDocument();
    expect(await axeViolations(container)).toEqual([]);
    await userEvent.click((await screen.findAllByRole('button', { name: 'View lines' }))[0]!);
    const dialog = await screen.findByRole('dialog');
    for (const label of ['Calculated', 'Entered', 'Provider']) expect(within(dialog).getByText(label)).toBeInTheDocument();
    expect(within(dialog).getByText('From provider worksheet')).toBeInTheDocument();
    await userEvent.click(within(dialog).getAllByRole('button', { name: 'Close' }).at(-1)!);
    await userEvent.click(screen.getByRole('button', { name: 'Approve level 2' }));
    await userEvent.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Approve' }));
    expect(source.decide).toHaveBeenCalledWith('run1', true, null);
  });

  it('says the API is needed instead of showing invented figures', async () => {
    setup(disconnectedPayrollSource, 'employee@wingora.test');
    expect((await screen.findAllByText('Payroll needs the Wingora API')).length).toBeGreaterThan(0);
  });
});
