import { configure, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createMemoryRouter, RouterProvider } from 'react-router';
import { describe, expect, it, vi } from 'vitest';
import { renderUi } from '@/test/render';
import { axeViolations } from '@/test/axe';
import { disconnectedPerformanceSource, PerformanceSourceProvider, type Cycle, type FeedbackRequest, type Meeting, type Objective, type PerformanceSource, type Review } from '@/api/performance';
import { SessionProvider } from '@/auth/session';
import { createMockGateway } from '@/mocks/mock-gateway';
import { personas } from '@/mocks/fixtures';
import { WorkspaceProvider } from '@/shell/workspace';
import { createRoutes } from '@/app/routes';

configure({ asyncUtilTimeout: 5000 });

/** Test doubles shaped like the Performance API (backend feature tests assert the real payloads). */
/** DataTable renders a table and a mobile card list, so each row control exists twice. */
const first = async (name: string | RegExp) => (await screen.findAllByRole('button', { name }))[0]!;
const page = <T,>(data: T[]) => ({ data, meta: { current_page: 1, last_page: 1, per_page: 25, total: data.length } });
const jordan = { id: 'emp1', name: 'Jordan Lee', employee_number: 'E-001' };
const sam = { id: 'emp2', name: 'Sam Patel', employee_number: 'E-002' };
const objective: Objective = {
  id: 'o1', title: 'Ship the mobile app', description: null, kind: 'okr', status: 'active', progress: 40, starts_on: null, due_on: '2027-06-30', completed_at: null,
  employee: jordan, parent: null, cycle: null, key_result_count: 1, version: 1, can_edit: true, updated_at: null,
};
const cycle: Cycle = {
  id: 'c1', name: 'H1 2027', status: 'draft', starts_on: '2027-01-01', ends_on: '2027-06-30', self_due_on: null, manager_due_on: null, rating_scale: 5,
  questions: [{ key: 'wins', label: 'Biggest wins', type: 'text' }, { key: 'growth', label: 'Growth', type: 'rating' }], review_count: null, completed_count: null, version: 1,
};
const review: Review = {
  id: 'r1', status: 'pending_self', cycle: { id: 'c1', name: 'H1 2027', status: 'open', self_due_on: null, manager_due_on: null, rating_scale: 5 }, employee: jordan, manager: sam,
  rating: null, self_submitted_at: null, manager_submitted_at: null, acknowledged_at: null, feedback_requested: null, feedback_submitted: null, can_self: true, can_manage: false, version: 3,
  questions: cycle.questions, self_answers: null, manager_answers: null, feedback: [],
};
const request: FeedbackRequest = {
  id: 'f1', status: 'requested', relationship: 'peer', anonymous: true, subject: sam, cycle: { id: 'c1', name: 'H1 2027', status: 'open', manager_due_on: null, rating_scale: 5 }, answers: null, submitted_at: null,
};
const meeting: Meeting = {
  id: 'm1', status: 'scheduled', scheduled_at: '2027-02-01T15:00:00Z', duration_minutes: 30, employee: jordan, manager: sam, open_actions: 0, is_manager: false, version: 1,
  agenda: 'Roadmap', shared_notes: null, action_items: [],
};

function fakeSource(overrides: Partial<PerformanceSource> = {}): PerformanceSource {
  return {
    ...disconnectedPerformanceSource,
    objectives: vi.fn(async () => page([objective])),
    objective: vi.fn(async () => ({ ...objective, key_results: [{ id: 'k1', title: 'Beta users', start_value: '0', target_value: '100', current_value: '40', unit: 'users', weight: 1, progress: 40 }], check_ins: [] })),
    saveObjective: vi.fn(async () => objective),
    checkIn: vi.fn(async () => objective),
    cycles: vi.fn(async () => page([cycle])),
    launchCycle: vi.fn(async () => ({ ...cycle, status: 'open' as const })),
    reviews: vi.fn(async () => page([review])),
    review: vi.fn(async () => review),
    submitSelf: vi.fn(async () => review),
    feedback: vi.fn(async () => page([request])),
    submitFeedback: vi.fn(async () => request),
    meetings: vi.fn(async () => page([meeting])),
    meeting: vi.fn(async () => meeting),
    people: vi.fn(async () => [jordan, sam]),
    ...overrides,
  };
}

function setup(persona: keyof typeof personas, source: PerformanceSource) {
  const router = createMemoryRouter(createRoutes(), { initialEntries: ['/performance'] });
  return renderUi(
    <SessionProvider gateway={createMockGateway(0)} initial={personas[persona]!}>
      <WorkspaceProvider><PerformanceSourceProvider value={source}><RouterProvider router={router} /></PerformanceSourceProvider></WorkspaceProvider>
    </SessionProvider>,
  );
}

describe('Performance for employees', () => {
  it('shows their objectives without staff tabs and creates an OKR with key results', async () => {
    const source = fakeSource();
    const { container } = setup('employee@wingora.test', source);
    await first('Ship the mobile app');
    expect(source.objectives).toHaveBeenCalledWith(expect.objectContaining({ scope: 'mine' }), expect.anything());
    expect(screen.queryByRole('tab', { name: 'Review cycles' })).toBeNull();
    expect(screen.queryByRole('combobox', { name: 'Show' })).toBeNull();
    expect(await axeViolations(container)).toEqual([]);

    await userEvent.click(screen.getByRole('button', { name: 'New objective' }));
    const dialog = await screen.findByRole('dialog');
    expect(within(dialog).queryByRole('combobox', { name: 'Owner' })).toBeNull();
    await userEvent.type(within(dialog).getByRole('textbox', { name: /^Title/ }), 'Grow adoption');
    await userEvent.type(within(dialog).getByRole('textbox', { name: /Key result 1/ }), 'Weekly active users');
    await userEvent.click(within(dialog).getByRole('button', { name: 'Create' }));
    await waitFor(() => expect(source.saveObjective).toHaveBeenCalledWith(expect.objectContaining({
      kind: 'okr', title: 'Grow adoption', key_results: [expect.objectContaining({ title: 'Weekly active users', target_value: 100 })],
    })));
  });

  it('records a key-result check-in from the objective drawer', async () => {
    const source = fakeSource();
    setup('employee@wingora.test', source);
    await userEvent.click(await first('Ship the mobile app'));
    const drawer = await screen.findByRole('dialog');
    await within(drawer).findAllByText('Beta users');
    await userEvent.type(within(drawer).getByRole('spinbutton', { name: /Current value/ }), '55');
    await userEvent.click(within(drawer).getByRole('button', { name: 'Save check-in' }));
    await waitFor(() => expect(source.checkIn).toHaveBeenCalledWith('o1', { key_result_id: 'k1', value: 55, note: null }));
  });

  it('submits a self assessment while the manager assessment stays hidden', async () => {
    const source = fakeSource();
    setup('employee@wingora.test', source);
    await userEvent.click(await screen.findByRole('tab', { name: 'Reviews' }));
    await userEvent.click(await first('Jordan Lee'));
    const drawer = await screen.findByRole('dialog');
    within(drawer).getByText("Waiting for the manager's review.");
    await userEvent.type(within(drawer).getByRole('textbox', { name: /Biggest wins/ }), 'Shipped beta');
    await userEvent.selectOptions(within(drawer).getByRole('combobox', { name: /Growth/ }), '4');
    await userEvent.click(within(drawer).getByRole('button', { name: 'Submit self assessment' }));
    await waitFor(() => expect(source.submitSelf).toHaveBeenCalledWith('r1', 3, { wins: 'Shipped beta', growth: 4 }));
  });

  it('answers an anonymous 360 request and hides private notes on a one-to-one', async () => {
    const source = fakeSource();
    setup('employee@wingora.test', source);
    await userEvent.click(await screen.findByRole('tab', { name: '360° feedback' }));
    await userEvent.click(await screen.findByRole('button', { name: 'Give feedback' }));
    const dialog = await screen.findByRole('dialog');
    within(dialog).getByText("The reviewer's name is not shown to anyone, including HR.");
    await userEvent.type(within(dialog).getByRole('textbox', { name: /Strengths/ }), 'Clear writing');
    await userEvent.type(within(dialog).getByRole('textbox', { name: /Areas to improve/ }), 'Delegate more');
    await userEvent.click(within(dialog).getByRole('button', { name: 'Send' }));
    await waitFor(() => expect(source.submitFeedback).toHaveBeenCalledWith('f1', { strengths: 'Clear writing', improvements: 'Delegate more', rating: null }));

    await userEvent.click(screen.getByRole('tab', { name: 'One-to-ones' }));
    await userEvent.click(await first(/2027/));
    const drawer = await screen.findByRole('dialog');
    await within(drawer).findByRole('textbox', { name: /Shared notes/ });
    expect(within(drawer).queryByRole('textbox', { name: /Private notes/ })).toBeNull();
  });
});

describe('Performance for HR', () => {
  it('launches a review cycle only after confirmation', async () => {
    const source = fakeSource();
    setup('hr@wingora.test', source);
    await userEvent.click(await screen.findByRole('tab', { name: 'Review cycles' }));
    await userEvent.click(await first('Launch'));
    const confirm = await screen.findByRole('alertdialog');
    expect(source.launchCycle).not.toHaveBeenCalled();
    await userEvent.click(within(confirm).getByRole('button', { name: 'Launch' }));
    await waitFor(() => expect(source.launchCycle).toHaveBeenCalledWith('c1', 1));
  });

  it('can switch objectives to the team scope', async () => {
    const source = fakeSource();
    setup('hr@wingora.test', source);
    await userEvent.selectOptions(await screen.findByRole('combobox', { name: 'Show' }), 'team');
    await waitFor(() => expect(source.objectives).toHaveBeenCalledWith(expect.objectContaining({ scope: 'team' }), expect.anything()));
  });
});
