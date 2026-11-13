import { configure, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createMemoryRouter, RouterProvider } from 'react-router';
import { describe, expect, it, vi } from 'vitest';
import { renderUi } from '@/test/render';
import { axeViolations } from '@/test/axe';
import { ApiError } from '@/api/http';
import { disconnectedTimeSource, TimeSourceProvider, type AttendanceToday, type LeaveRequestItem, type TimeSource } from '@/api/time';
import { SessionProvider } from '@/auth/session';
import { createMockGateway } from '@/mocks/mock-gateway';
import { personas } from '@/mocks/fixtures';
import { WorkspaceProvider } from '@/shell/workspace';
import { createRoutes } from '@/app/routes';

configure({ asyncUtilTimeout: 5000 });

/** Test doubles shaped like the Attendance and Leave API (backend feature tests assert the real payloads). */
const jordan = { id: 'emp_jordan', name: 'Jordan Lee', employee_number: 'E-200' };
const empty = { data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 } };
const today = (open: boolean): AttendanceToday => ({
  employee: jordan, timezone: 'America/New_York', today: '2027-03-01', can_clock: true, shift: { name: 'Day', start_time: '09:00', end_time: '17:00' },
  record: open ? { id: 'rec1', employee: jordan, work_date: '2027-03-01', timezone: 'America/New_York', clock_in_at: '2027-03-01T14:20:00Z', clock_out_at: null,
    clock_in_local: '2027-03-01 09:20', clock_out_local: null, shift: null, worked_minutes: null, late_minutes: 20, early_minutes: 0, overtime_minutes: 0, source: 'clock', note: null, open: true } : null,
});
const pendingLeave: LeaveRequestItem = {
  id: 'lr1', employee: jordan, leave_type: { id: 'lt1', name: 'Vacation', code: 'vacation', is_paid: true }, start_date: '2027-03-01', end_date: '2027-03-05',
  half_day: null, days: 4, reason: null, status: 'pending', current_step: 1, approval_steps: 2, cancellation_reason: null, created_at: null, approvals: [], can_decide: true, can_cancel: false,
};

function fakeSource(overrides: Partial<TimeSource> = {}): TimeSource {
  return {
    ...disconnectedTimeSource,
    today: vi.fn(async () => today(false)),
    clockIn: vi.fn(async () => today(true).record!),
    records: vi.fn(async () => empty),
    attendanceRequests: vi.fn(async () => empty),
    balances: vi.fn(async () => [{ leave_type: { id: 'lt1', name: 'Vacation', code: 'vacation', is_paid: true }, year: 2027, entitled: 12, carried_forward: 0, adjustments: 0, used: 0, lapsed: 0, carried_out: 0, expired: 0, pending: 4, available: 8 }]),
    ledger: vi.fn(async () => empty),
    leaveRequests: vi.fn(async () => empty),
    leaveTypes: vi.fn(async () => [{ id: 'lt1', name: 'Vacation', code: 'vacation', country_code: null, rule_key: null, is_paid: true, allow_half_day: true, requires_document: false, approval_steps: 1 as const, status: 'active' as const }]),
    previewLeave: vi.fn(async () => 4),
    holidays: vi.fn(async () => ({ data: [], meta: { work_location: null, timezone: 'UTC' } })),
    ...overrides,
  };
}

function setup(source: TimeSource, path: string, persona: keyof typeof personas = 'employee@wingora.test') {
  const router = createMemoryRouter(createRoutes(), { initialEntries: [path] });
  return renderUi(
    <SessionProvider gateway={createMockGateway(0)} initial={personas[persona]!}>
      <WorkspaceProvider><TimeSourceProvider value={source}><RouterProvider router={router} /></TimeSourceProvider></WorkspaceProvider>
    </SessionProvider>,
  );
}

describe('attendance', () => {
  it('clocks in, then shows the open record and clock-out', async () => {
    let open = false;
    const source = fakeSource({ today: vi.fn(async () => today(open)), clockIn: vi.fn(async () => { open = true; return today(true).record!; }) });
    const { container } = setup(source, '/attendance');
    const clockIn = await screen.findByRole('button', { name: 'Clock in' });
    expect(await axeViolations(container)).toEqual([]);
    await userEvent.click(clockIn);
    expect(source.clockIn).toHaveBeenCalledTimes(1);
    expect(await screen.findByRole('button', { name: 'Clock out' })).toBeInTheDocument();
    expect(screen.getByText(/Clocked in at 09:20/)).toBeInTheDocument();
    expect(screen.getByText('Late by 20m')).toBeInTheDocument();
  });

  it('shows the server message when a duplicate clock-in is rejected', async () => {
    const source = fakeSource({ clockIn: vi.fn(async () => { throw new ApiError(409, { detail: 'You are already clocked in.' }); }) });
    setup(source, '/attendance');
    await userEvent.click(await screen.findByRole('button', { name: 'Clock in' }));
    expect(await screen.findByText('You are already clocked in.')).toBeInTheDocument();
  });

  it('hides approvals, the report and settings from employees', async () => {
    setup(fakeSource(), '/attendance');
    const tabs = await screen.findByRole('tablist', { name: 'Attendance' });
    expect(within(tabs).getAllByRole('tab').map((t) => t.textContent)).toEqual(['My attendance']);
  });

  it('shows approver tabs to HR', async () => {
    setup(fakeSource(), '/attendance', 'hr@wingora.test');
    const tabs = await screen.findByRole('tablist', { name: 'Attendance' });
    expect(within(tabs).getAllByRole('tab').map((t) => t.textContent)).toEqual(['My attendance', 'Approvals', 'Report']);
  });
});

describe('leave', { timeout: 20_000 }, () => {
  it('shows balances and submits a request with the server-counted working days', async () => {
    const source = fakeSource({ applyLeave: vi.fn(async () => pendingLeave) });
    setup(source, '/leave');
    expect(await screen.findByText('8')).toBeInTheDocument();
    await userEvent.click(screen.getByRole('button', { name: 'Request leave' }));
    const dialog = await screen.findByRole('dialog', { name: 'Request leave' });
    await userEvent.selectOptions(within(dialog).getByRole('combobox', { name: /Leave type/ }), 'lt1');
    await userEvent.type(within(dialog).getByLabelText(/Start date/), '2027-03-01');
    await userEvent.clear(within(dialog).getByLabelText(/End date/));
    await userEvent.type(within(dialog).getByLabelText(/End date/), '2027-03-05');
    expect(await within(dialog).findByText('4 working days')).toBeInTheDocument();
    await userEvent.click(within(dialog).getByRole('button', { name: 'Submit' }));
    await waitFor(() => expect(source.applyLeave).toHaveBeenCalledWith(expect.objectContaining({ leave_type: 'lt1', start_date: '2027-03-01', end_date: '2027-03-05' })));
  });

  it('shows validation errors inline in the dialog', async () => {
    const source = fakeSource({ applyLeave: vi.fn(async () => { throw new ApiError(422, { errors: { start_date: ['This overlaps another leave request.'] } }); }) });
    setup(source, '/leave');
    await userEvent.click(await screen.findByRole('button', { name: 'Request leave' }));
    const dialog = await screen.findByRole('dialog', { name: 'Request leave' });
    await userEvent.selectOptions(within(dialog).getByRole('combobox', { name: /Leave type/ }), 'lt1');
    await userEvent.type(within(dialog).getByLabelText(/Start date/), '2027-03-01');
    await userEvent.click(within(dialog).getByRole('button', { name: 'Submit' }));
    expect(await within(dialog).findByText('This overlaps another leave request.')).toBeInTheDocument();
  });

  it('lets an approver reject with a required note', async () => {
    const source = fakeSource({ leaveRequests: vi.fn(async (scope) => (scope === 'approvals' ? { ...empty, data: [pendingLeave], meta: { ...empty.meta, total: 1 } } : empty)), decideLeave: vi.fn(async () => ({ ...pendingLeave, status: 'rejected' as const })) });
    setup(source, '/leave', 'hr@wingora.test');
    await userEvent.click(await screen.findByRole('tab', { name: 'Approvals' }));
    await userEvent.click((await screen.findAllByRole('button', { name: 'Reject' }))[0]!);
    const dialog = await screen.findByRole('dialog', { name: 'Reject request' });
    await userEvent.type(within(dialog).getByLabelText(/Reason for rejection/), 'Team coverage');
    await userEvent.click(within(dialog).getByRole('button', { name: 'Reject' }));
    await waitFor(() => expect(source.decideLeave).toHaveBeenCalledWith('lr1', false, 'Team coverage'));
  });

  it('explains when the API is not connected', async () => {
    setup(disconnectedTimeSource, '/leave');
    expect((await screen.findAllByText('Attendance and leave need the Wingora API')).length).toBeGreaterThan(0);
  });
});
