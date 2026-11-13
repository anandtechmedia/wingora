import { configure, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createMemoryRouter, RouterProvider } from 'react-router';
import { describe, expect, it, vi } from 'vitest';
import { renderUi } from '@/test/render';
import { axeViolations } from '@/test/axe';
import { disconnectedProjectsSource, ProjectsSourceProvider, type MyWeek, type Project, type ProjectsSource, type Task, type Timesheet } from '@/api/projects';
import { EmployeeSourceProvider, type EmployeeSource, type EmployeeSummary } from '@/api/employees';
import { SessionProvider } from '@/auth/session';
import { createMockGateway } from '@/mocks/mock-gateway';
import { personas } from '@/mocks/fixtures';
import { WorkspaceProvider } from '@/shell/workspace';
import { createRoutes } from '@/app/routes';
import { mondayOf } from '.';

configure({ asyncUtilTimeout: 5000 });

/** Test doubles shaped like the Projects API (backend feature tests assert the real payloads). */
const page = <T,>(data: T[]) => ({ data, meta: { current_page: 1, last_page: 1, per_page: 25, total: data.length } });
const jordan = { id: 'emp1', name: 'Jordan Lee', employee_number: 'E-001' };
const project: Project = {
  id: 'p1', code: 'WEB', name: 'Website relaunch', client: 'Acme', description: null, status: 'active', starts_on: '2027-01-01', ends_on: null, budget_hours: '100.00',
  manager: jordan, members_count: 1, tasks_count: 1, can_manage: true, version: 2, members: [jordan],
};
const task: Task = { id: 't1', title: 'Landing page', description: null, status: 'todo', assignee: jordan, due_on: '2027-03-05', estimate_hours: null, version: 1, project: { id: 'p1', code: 'WEB', name: 'Website relaunch' } };
const blankWeek: MyWeek = {
  timesheet: { id: null, week_start: '2027-03-01', status: 'draft', total_minutes: 0, version: 0, entries: [] },
  projects: [{ id: 'p1', code: 'WEB', name: 'Website relaunch', tasks: [{ id: 't1', title: 'Landing page' }] }],
};
const saved: Timesheet = { id: 's1', week_start: '2027-03-01', status: 'draft', total_minutes: 450, version: 1 };
const submitted: Timesheet = {
  ...saved, status: 'submitted', version: 2, employee: jordan,
  entries: [{ project: { id: 'p1', code: 'WEB', name: 'Website relaunch' }, task: { id: 't1', title: 'Landing page' }, work_date: '2027-03-01', minutes: 450, note: null }],
};

function fakeSource(overrides: Partial<ProjectsSource> = {}): ProjectsSource {
  return {
    ...disconnectedProjectsSource,
    myWeek: vi.fn(async () => blankWeek),
    saveWeek: vi.fn(async () => saved),
    submit: vi.fn(async () => submitted),
    myTasks: vi.fn(async () => page([task])),
    saveTask: vi.fn(async () => task),
    projects: vi.fn(async () => page([project])),
    project: vi.fn(async () => project),
    saveProject: vi.fn(async () => project),
    tasks: vi.fn(async () => page([task])),
    report: vi.fn(async () => ({ totals: { approved_minutes: 600, pending_minutes: 60, budget_minutes: 6000, budget_used_percent: 10 }, by_employee: [{ employee: jordan, approved_minutes: 600, pending_minutes: 60 }], by_task: [], by_week: [] })),
    approvals: vi.fn(async () => page([submitted])),
    timesheet: vi.fn(async () => submitted),
    decide: vi.fn(async () => ({ ...submitted, status: 'approved' as const })),
    ...overrides,
  };
}
const people = { list: vi.fn(async () => page([{ id: 'emp1', employee_number: 'E-001', first_name: 'Jordan', last_name: 'Lee', preferred_name: null } as unknown as EmployeeSummary])) } as unknown as EmployeeSource;

function setup(persona: keyof typeof personas, source: ProjectsSource) {
  const router = createMemoryRouter(createRoutes(), { initialEntries: ['/projects'] });
  return renderUi(
    <SessionProvider gateway={createMockGateway(0)} initial={personas[persona]!}>
      <WorkspaceProvider><EmployeeSourceProvider value={people}><ProjectsSourceProvider value={source}><RouterProvider router={router} /></ProjectsSourceProvider></EmployeeSourceProvider></WorkspaceProvider>
    </SessionProvider>,
  );
}

describe('Projects module', () => {
  it('computes the Monday of a week', () => {
    expect([mondayOf('2027-03-01'), mondayOf('2027-03-03'), mondayOf('2027-03-07')]).toEqual(['2027-03-01', '2027-03-01', '2027-03-01']);
  });

  it('lets an employee log hours, then save and submit the week', async () => {
    const source = fakeSource();
    setup('employee@wingora.test', source);
    expect(await screen.findByRole('tab', { name: 'My timesheet' })).toBeInTheDocument();
    expect(screen.queryByRole('tab', { name: 'Approvals' })).not.toBeInTheDocument();
    await userEvent.click(await screen.findByRole('button', { name: 'Add row' }));
    await userEvent.selectOptions(screen.getByRole('combobox', { name: 'Project, row 1' }), 'p1');
    await userEvent.selectOptions(screen.getByRole('combobox', { name: 'Task, row 1' }), 't1');
    const monday = screen.getAllByRole('textbox').find((x) => x.getAttribute('aria-label')?.startsWith('Hours, row 1') ?? false)
      ?? screen.getAllByLabelText(/^Hours, row 1/)[0]!;
    await userEvent.type(monday, '7.5');
    expect(screen.getByText('7.5 h total')).toBeInTheDocument();
    await userEvent.click(screen.getByRole('button', { name: 'Submit week' }));
    await waitFor(() => expect(source.submit).toHaveBeenCalledWith('s1', 1));
    expect(source.saveWeek).toHaveBeenCalledWith('2027-03-01', [{ project_id: 'p1', task_id: 't1', work_date: '2027-03-01', minutes: 450, note: null }], 0);
  });

  it('blocks saving when an hours cell is not a number', async () => {
    const source = fakeSource();
    setup('employee@wingora.test', source);
    await userEvent.click(await screen.findByRole('button', { name: 'Add row' }));
    await userEvent.selectOptions(screen.getByRole('combobox', { name: 'Project, row 1' }), 'p1');
    await userEvent.type(screen.getAllByLabelText(/^Hours, row 1/)[0]!, '7..5');
    expect(screen.getAllByText('Enter hours as a number, for example 7.5.').length).toBeGreaterThan(0);
    expect(screen.getByRole('button', { name: 'Save draft' })).toBeDisabled();
  });

  it('lets an assignee move their task status', async () => {
    const source = fakeSource();
    setup('employee@wingora.test', source);
    await userEvent.click(await screen.findByRole('tab', { name: 'My tasks' }));
    await userEvent.selectOptions((await screen.findAllByRole('combobox', { name: 'Status of Landing page' }))[0]!, 'in_progress');
    await waitFor(() => expect(source.saveTask).toHaveBeenCalledWith({ status: 'in_progress' }, 'p1', 't1', 1));
  }, 20_000);

  it('lets HR open a project with its report and approve a submitted timesheet', async () => {
    const source = fakeSource();
    const { container } = setup('hr@wingora.test', source);
    await userEvent.click(await screen.findByRole('tab', { name: 'Projects' }));
    await userEvent.click((await screen.findAllByRole('button', { name: /Website relaunch/ }))[0]!);
    const drawer = await screen.findByRole('dialog', { name: /WEB · Website relaunch/ });
    expect(await within(drawer).findByText('10%')).toBeInTheDocument();
    expect(within(drawer).getByRole('button', { name: 'New task' })).toBeInTheDocument();
    await userEvent.click(within(drawer).getByRole('button', { name: 'Close' }));

    await userEvent.click(screen.getByRole('tab', { name: 'Approvals' }));
    const reviewer = (await screen.findAllByRole('button', { name: 'Jordan Lee' }))[0]!;
    expect(await axeViolations(container)).toEqual([]);
    await userEvent.click(reviewer);
    const review = await screen.findByRole('dialog', { name: /Jordan Lee · week of/ });
    await userEvent.click(await within(review).findByRole('button', { name: 'Approve' }));
    await waitFor(() => expect(source.decide).toHaveBeenCalledWith('s1', 'approve', 2, null));
  }, 20_000);

  it('explains when the API is not connected', async () => {
    setup('employee@wingora.test', disconnectedProjectsSource);
    expect(await screen.findByText('Projects are not connected')).toBeInTheDocument();
  });
});
