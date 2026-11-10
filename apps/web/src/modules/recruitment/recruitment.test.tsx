import { configure, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createMemoryRouter, RouterProvider } from 'react-router';
import { describe, expect, it, vi } from 'vitest';
import { renderUi } from '@/test/render';
import { axeViolations } from '@/test/axe';
import { ApiError } from '@/api/http';
import {
  CareersSourceProvider, disconnectedCareersSource, disconnectedRecruitmentSource, RecruitmentSourceProvider,
  type Application, type BoardColumn, type CareersSite, type CareersSource, type OnboardingTask, type RecruitmentSource,
} from '@/api/recruitment';
import { SessionProvider } from '@/auth/session';
import { createMockGateway } from '@/mocks/mock-gateway';
import { personas } from '@/mocks/fixtures';
import { WorkspaceProvider } from '@/shell/workspace';
import { createRoutes } from '@/app/routes';

configure({ asyncUtilTimeout: 5000 });

/** Test doubles shaped like the Recruitment and Onboarding API (backend feature tests assert the real payloads). */
const page = <T,>(data: T[]) => ({ data, meta: { current_page: 1, last_page: 1, per_page: 25, total: data.length } });
const stage = (id: string, name: string, kind: 'open' | 'hired' | 'rejected' = 'open', sort_order = 1) => ({ id, key: id, name, kind, sort_order });
const card: Application = {
  id: 'app1', status: 'active', version: 4, position: 0, stage: stage('s1', 'Applied'), opening: { id: 'op1', title: 'Payroll Specialist', status: 'published' },
  candidate: { id: 'c1', name: 'Ana Diaz', first_name: 'Ana', last_name: 'Diaz', email: 'ana@example.test', phone: null, location: null, linkedin_url: null, source: 'careers', on_hold: false, anonymized: false },
  applied_at: '2027-01-10T10:00:00Z', consent: { version: '1', given_at: '2027-01-10T10:00:00Z' }, retain_until: null, rejection_reason: null, cover_letter: null, employee: null,
  latest_offer_status: null, documents: null, can: { act: true, manage: true, offers: true },
};
const board: BoardColumn[] = [
  { ...stage('s1', 'Applied'), cards: [card] }, { ...stage('s2', 'Interview', 'open', 2), cards: [] },
  { ...stage('s3', 'Hired', 'hired', 3), cards: [] }, { ...stage('s4', 'Rejected', 'rejected', 4), cards: [] },
];
const task: OnboardingTask = {
  id: 't1', stage: 'first_day', title: 'Sign the handbook', description: null, assignee: 'employee', assignee_user: null, due_on: '2027-02-01', overdue: true, status: 'pending',
  review_note: null, document: null, completed_at: null, can: { work: true, review: false }, employee: { id: 'e1', name: 'Jordan Lee' }, plan_id: 'pl1',
};

function fakeSource(overrides: Partial<RecruitmentSource> = {}): RecruitmentSource {
  return {
    ...disconnectedRecruitmentSource,
    openings: vi.fn(async () => page([])),
    board: vi.fn(async () => board),
    move: vi.fn(async () => ({ ...card, stage: board[1]!, version: 5 })),
    myTasks: vi.fn(async () => [task]),
    completeTask: vi.fn(async () => ({ ...task, status: 'completed' as const })),
    interviews: vi.fn(async () => []),
    ...overrides,
  };
}

function setup(path: string, persona: keyof typeof personas, source: RecruitmentSource, careers: CareersSource = disconnectedCareersSource) {
  const router = createMemoryRouter(createRoutes(), { initialEntries: [path] });
  return renderUi(
    <SessionProvider gateway={createMockGateway(0)} initial={personas[persona]!}>
      <WorkspaceProvider><RecruitmentSourceProvider value={source}><CareersSourceProvider value={careers}><RouterProvider router={router} /></CareersSourceProvider></RecruitmentSourceProvider></WorkspaceProvider>
    </SessionProvider>,
  );
}

describe('Recruitment pipeline', () => {
  it('moves a card with the current version and only offers open stages', async () => {
    const source = fakeSource();
    setup('/recruitment', 'hr@wingora.test', source);
    const menu = await screen.findByLabelText('Move Ana Diaz to');
    const options = within(menu).getAllByRole('option').map((o) => o.textContent);
    expect(options).toContain('Interview');
    expect(options).not.toContain('Hired');
    expect(options).not.toContain('Rejected');
    await userEvent.selectOptions(menu, 's2');
    expect(source.move).toHaveBeenCalledWith('app1', 's2', 4);
  });

  it('explains a conflicting move and reloads the board', async () => {
    const source = fakeSource({ move: vi.fn(async () => { throw new ApiError(409, { message: 'Conflict' }); }) });
    setup('/recruitment', 'hr@wingora.test', source);
    await userEvent.selectOptions(await screen.findByLabelText('Move Ana Diaz to'), 's2');
    expect(await screen.findByText(/Someone else moved this candidate/)).toBeInTheDocument();
    expect(source.board).toHaveBeenCalledTimes(2);
  });

  it('hides the pipeline from employees who only interview', async () => {
    setup('/recruitment', 'employee@wingora.test', fakeSource());
    expect(await screen.findByRole('heading', { name: 'My interviews' })).toBeInTheDocument();
    expect(screen.queryByRole('tab', { name: 'Pipeline' })).not.toBeInTheDocument();
    expect(screen.queryByRole('tab', { name: 'Settings' })).not.toBeInTheDocument();
  });
});

describe('Onboarding', () => {
  it('lets a new employee complete their own task without seeing other plans', async () => {
    const source = fakeSource();
    const { container } = setup('/onboarding', 'employee@wingora.test', source);
    expect(await screen.findByText('Sign the handbook')).toBeInTheDocument();
    expect(screen.getByText('Overdue')).toBeInTheDocument();
    expect(screen.queryByRole('tab', { name: 'Employees onboarding' })).not.toBeInTheDocument();
    expect(await axeViolations(container)).toEqual([]);
    await userEvent.click(screen.getByRole('button', { name: 'Mark done' }));
    expect(source.completeTask).toHaveBeenCalledWith('t1', null);
  });
});

describe('Public careers page', () => {
  const site: CareersSite = {
    organization: 'App Wings LLC', headline: null, intro: 'Build HR software.', consent: { version: '3', text: 'We use your data to assess your application.', retention_days: 180 },
    openings: [{ slug: 'payroll-specialist', title: 'Payroll Specialist', summary: 'Own payroll prep.', description: null, requirements: null, employment_type: 'full_time',
      workplace: 'hybrid', department: 'Finance', location: 'Austin, US', closes_on: null, published_at: '2027-01-01T00:00:00Z', open: true }],
  };
  const careers = (): CareersSource => ({
    site: vi.fn(async () => site),
    opening: vi.fn(async () => ({ ...site.openings[0]!, description: 'Prepare payroll for review.' })),
    apply: vi.fn(async () => undefined),
  });

  it('lists published openings without a session', async () => {
    const source = careers();
    const { container } = setup('/careers/app-wings', 'employee@wingora.test', fakeSource(), source);
    expect(await screen.findByRole('heading', { name: 'Join App Wings LLC' })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /Payroll Specialist/ })).toHaveAttribute('href', '/careers/app-wings/payroll-specialist');
    expect(source.site).toHaveBeenCalledWith('app-wings', expect.anything());
    expect(await axeViolations(container)).toEqual([]);
  });

  it('requires consent and a CV, and sends the consent version shown', async () => {
    const source = careers();
    setup('/careers/app-wings/payroll-specialist', 'employee@wingora.test', fakeSource(), source);
    const submit = await screen.findByRole('button', { name: 'Submit application' });
    await userEvent.type(screen.getByLabelText(/First name/), 'Ana');
    await userEvent.type(screen.getByLabelText(/Last name/), 'Diaz');
    await userEvent.type(screen.getByLabelText(/Email/), 'ana@example.test');
    expect(submit).toBeDisabled();
    await userEvent.upload(screen.getByLabelText(/CV \(PDF or DOCX\)/), new File(['%PDF-1.4'], 'cv.pdf', { type: 'application/pdf' }));
    expect(submit).toBeDisabled();
    await userEvent.click(screen.getByLabelText(/I agree to this privacy notice/));
    await userEvent.click(submit);
    expect(source.apply).toHaveBeenCalledWith('app-wings', 'payroll-specialist', expect.objectContaining({ consent: true, consent_version: '3', website: '' }));
    expect(await screen.findByText('Application received')).toBeInTheDocument();
  });
});
