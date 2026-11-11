import { configure, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createMemoryRouter, RouterProvider } from 'react-router';
import { describe, expect, it, vi } from 'vitest';
import { renderUi } from '@/test/render';
import { axeViolations } from '@/test/axe';
import { ApiError } from '@/api/http';
import { disconnectedHelpdeskSource, HelpdeskSourceProvider, type Article, type HelpdeskSource, type Ticket } from '@/api/helpdesk';
import { SessionProvider } from '@/auth/session';
import { createMockGateway } from '@/mocks/mock-gateway';
import { personas } from '@/mocks/fixtures';
import { WorkspaceProvider } from '@/shell/workspace';
import { createRoutes } from '@/app/routes';

configure({ asyncUtilTimeout: 5000 });

/** Test doubles shaped like the Helpdesk API (backend feature tests assert the real payloads). */
const page = <T,>(data: T[]) => ({ data, meta: { current_page: 1, last_page: 1, per_page: 25, total: data.length } });
const at = '2027-01-10T10:00:00Z';
const ticket: Ticket = {
  id: 'tk1', number: 7, subject: 'Visa letter', status: 'open', priority: 'normal', category: { id: 'c1', name: 'Benefits', restricted: false },
  requester: { id: 'usr_employee', name: 'Jordan Lee' }, assignee: null, due_at: '2027-01-13T10:00:00Z', overdue: false, first_response_at: null,
  resolved_at: null, closed_at: null, created_at: at, updated_at: at, version: 1, next: ['closed'],
  can: { work: false, assign: false, take: false, internal_notes: false, comment: true }, description: 'I need a visa letter for travel.',
  comments: [{ id: 'cm1', body: 'We are on it.', internal: false, created_at: at, author: { id: 'usr_hr', name: 'Priya Nair', staff: true }, attachments: [] }], attachments: [],
};
const staffTicket: Ticket = {
  ...ticket, version: 3, next: ['in_progress', 'waiting', 'resolved'], can: { work: true, assign: true, take: true, internal_notes: true, comment: true },
  comments: [...ticket.comments!, { id: 'cm2', body: 'Check passport expiry first.', internal: true, created_at: at, author: { id: 'usr_hr', name: 'Priya Nair', staff: true }, attachments: [] }],
};
const faq: Article = { id: 'a1', kind: 'faq', title: 'How do I update my address?', summary: null, status: 'published', category: null, published_at: at, updated_at: at, sort_order: 0, body: 'Open your profile and edit it.', can: { edit: true } };
const draft: Article = { id: 'a2', kind: 'article', title: 'Leave policy', summary: 'How leave works', status: 'draft', category: null, published_at: null, updated_at: at, sort_order: 0, can: { edit: true } };

function fakeSource(overrides: Partial<HelpdeskSource> = {}): HelpdeskSource {
  return {
    ...disconnectedHelpdeskSource,
    tickets: vi.fn(async () => page([ticket])),
    ticket: vi.fn(async () => ticket),
    categories: vi.fn(async () => [{ id: 'c1', name: 'Benefits', description: 'Insurance and perks', restricted: false, active: true, sort_order: 0 }]),
    agents: vi.fn(async () => [{ id: 'usr_hr', name: 'Priya Nair' }]),
    comment: vi.fn(async () => ticket),
    createTicket: vi.fn(async () => ({ ...ticket, id: 'tk2', number: 8 })),
    articles: vi.fn(async () => page([faq, draft])),
    publishArticle: vi.fn(async () => ({ ...draft, status: 'published' as const })),
    settings: vi.fn(async () => ({ due_hours: { low: 120, normal: 72, high: 24, urgent: 8 } })),
    saveSettings: vi.fn(async (due_hours) => ({ due_hours })),
    ...overrides,
  };
}

function setup(persona: keyof typeof personas, source: HelpdeskSource) {
  const router = createMemoryRouter(createRoutes(), { initialEntries: ['/helpdesk'] });
  return renderUi(
    <SessionProvider gateway={createMockGateway(0)} initial={personas[persona]!}>
      <WorkspaceProvider><HelpdeskSourceProvider value={source}><RouterProvider router={router} /></HelpdeskSourceProvider></WorkspaceProvider>
    </SessionProvider>,
  );
}

describe('Helpdesk for employees', () => {
  it('lists only their tickets, hides staff tabs and replies without internal-note controls', async () => {
    const source = fakeSource();
    const { container } = setup('employee@wingora.test', source);
    await screen.findAllByRole('button', { name: /Visa letter/ });
    expect(source.tickets).toHaveBeenCalledWith(expect.objectContaining({ scope: 'mine', status: 'active' }), expect.anything());
    expect(screen.queryByRole('tab', { name: 'Queue' })).toBeNull();
    expect(screen.queryByRole('tab', { name: 'Settings' })).toBeNull();
    expect(await axeViolations(container)).toEqual([]);

    await userEvent.click(screen.getAllByRole('button', { name: /Visa letter/ })[0]!);
    const drawer = await screen.findByRole('dialog');
    await within(drawer).findByText('We are on it.');
    expect(within(drawer).queryByRole('checkbox', { name: /Internal note/ })).toBeNull();
    expect(within(drawer).queryByRole('combobox', { name: 'Assignee' })).toBeNull();
    await userEvent.type(within(drawer).getByRole('textbox', { name: /Reply/ }), 'Thank you');
    await userEvent.click(within(drawer).getByRole('button', { name: 'Send reply' }));
    await waitFor(() => expect(source.comment).toHaveBeenCalledWith('tk1', 'Thank you', false, []));
  });

  it('creates a ticket with a category and priority', async () => {
    const source = fakeSource({ tickets: vi.fn(async () => page<Ticket>([])) });
    setup('employee@wingora.test', source);
    await screen.findAllByText('You have no tickets.');
    await userEvent.click(screen.getByRole('button', { name: 'New ticket' }));
    const dialog = await screen.findByRole('dialog');
    await within(dialog).findByRole('option', { name: 'Benefits' });
    await userEvent.selectOptions(within(dialog).getByRole('combobox', { name: /Category/ }), 'c1');
    expect(within(dialog).getByText('Insurance and perks')).toBeTruthy();
    await userEvent.selectOptions(within(dialog).getByRole('combobox', { name: /Priority/ }), 'high');
    await userEvent.type(within(dialog).getByRole('textbox', { name: /Subject/ }), 'Payslip question');
    await userEvent.type(within(dialog).getByRole('textbox', { name: /Description/ }), 'My November payslip is missing.');
    await userEvent.click(within(dialog).getByRole('button', { name: 'Submit ticket' }));
    await waitFor(() => expect(source.createTicket).toHaveBeenCalledWith({ category_id: 'c1', subject: 'Payslip question', description: 'My November payslip is missing.', priority: 'high', files: [] }));
    expect(await screen.findByText('Ticket #8 created')).toBeTruthy();
  });

  it('shows a not-connected state without an API', async () => {
    setup('employee@wingora.test', disconnectedHelpdeskSource);
    expect(await screen.findByText('Helpdesk is not connected')).toBeTruthy();
  });
});

describe('Helpdesk for HR staff', () => {
  it('opens the queue, shows internal notes and reloads after a version conflict', async () => {
    const setStatus = vi.fn(async () => { throw new ApiError(409, { detail: 'This ticket was changed by someone else. Refresh and try again.' }); });
    const source = fakeSource({ tickets: vi.fn(async () => page([staffTicket])), ticket: vi.fn(async () => staffTicket), setStatus });
    const { container } = setup('hr@wingora.test', source);
    expect(await screen.findByRole('tab', { name: 'Queue', selected: true })).toBeTruthy();
    await screen.findAllByRole('button', { name: /Visa letter/ });
    expect(await axeViolations(container)).toEqual([]);
    await userEvent.click((await screen.findAllByRole('button', { name: /Visa letter/ }))[0]!);
    const drawer = await screen.findByRole('dialog');
    await within(drawer).findByText('Check passport expiry first.');
    expect(within(drawer).getByText('Internal', { selector: 'span' })).toBeTruthy();
    expect(within(drawer).getByRole('checkbox', { name: /Internal note/ })).toBeTruthy();

    const calls = vi.mocked(source.ticket).mock.calls.length;
    await userEvent.click(within(drawer).getByRole('button', { name: 'Resolve' }));
    expect(setStatus).toHaveBeenCalledWith('tk1', 'resolved', 3);
    expect(await screen.findByText('This ticket was changed by someone else. Refresh and try again.')).toBeTruthy();
    await waitFor(() => expect(vi.mocked(source.ticket).mock.calls.length).toBeGreaterThan(calls));
  });

  it('sends an internal note when the box is ticked', async () => {
    const source = fakeSource({ tickets: vi.fn(async () => page([staffTicket])), ticket: vi.fn(async () => staffTicket) });
    setup('hr@wingora.test', source);
    await userEvent.click((await screen.findAllByRole('button', { name: /Visa letter/ }))[0]!);
    const drawer = await screen.findByRole('dialog');
    await userEvent.click(await within(drawer).findByRole('checkbox', { name: /Internal note/ }));
    await userEvent.type(within(drawer).getByRole('textbox', { name: /Internal note/ }), 'Passport valid.');
    await userEvent.click(within(drawer).getByRole('button', { name: 'Add internal note' }));
    await waitFor(() => expect(source.comment).toHaveBeenCalledWith('tk1', 'Passport valid.', true, []));
  });

  it('shows FAQ answers inline and lets managers publish a draft', async () => {
    const source = fakeSource();
    setup('hr@wingora.test', source);
    await userEvent.click(await screen.findByRole('tab', { name: 'Knowledge base' }));
    expect(await screen.findByText('Open your profile and edit it.')).toBeTruthy();
    expect(screen.getByText('Draft')).toBeTruthy();
    await userEvent.click(screen.getByRole('button', { name: 'Publish Leave policy' }));
    await waitFor(() => expect(source.publishArticle).toHaveBeenCalledWith('a2', true));
  });

  it('lets a configurer change one due-by target and keeps the others', async () => {
    const source = fakeSource({ categories: vi.fn(async () => []) });
    setup('owner@wingora.test', source);
    await userEvent.click(await screen.findByRole('tab', { name: 'Settings' }));
    const urgent = await screen.findByRole('spinbutton', { name: 'Urgent (hours)' });
    await userEvent.clear(urgent);
    await userEvent.type(urgent, '4');
    await userEvent.click(screen.getByRole('button', { name: 'Save' }));
    await waitFor(() => expect(source.saveSettings).toHaveBeenCalledWith({ low: 120, normal: 72, high: 24, urgent: 4 }));
    expect((await screen.findAllByText(/No categories yet/)).length).toBeGreaterThan(0);
  });
});
