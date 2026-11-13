import { configure, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createMemoryRouter, RouterProvider } from 'react-router';
import { describe, expect, it, vi } from 'vitest';
import { renderUi } from '@/test/render';
import { axeViolations } from '@/test/axe';
import { disconnectedDocumentsSource, DocumentsSourceProvider, type DocumentRequest, type DocumentsSource, type DocumentTemplate } from '@/api/documents';
import { SessionProvider } from '@/auth/session';
import { createMockGateway } from '@/mocks/mock-gateway';
import { personas } from '@/mocks/fixtures';
import { WorkspaceProvider } from '@/shell/workspace';
import { createRoutes } from '@/app/routes';

configure({ asyncUtilTimeout: 5000 });

/** Test doubles shaped like the Document requests API (backend feature tests assert the real payloads). */
const page = <T,>(data: T[]) => ({ data, meta: { current_page: 1, last_page: 1, per_page: 25, total: data.length } });
const at = '2027-01-10T10:00:00Z';
const letter: DocumentTemplate = {
  id: 't1', name: 'Employment verification', description: 'For banks and embassies.', delivery: 'generated', requires_manager: true, includes_salary: false,
  fields: [{ key: 'purpose', label: 'Purpose', type: 'text', required: true }, { key: 'addressee', label: 'Addressed to', type: 'text', required: false }],
  legal_entity: null, active: true, sort_order: 0,
};
const retired: DocumentTemplate = { ...letter, id: 't2', name: 'Old reference letter', active: false };
const base: DocumentRequest = {
  id: 'r1', number: 4, status: 'pending_hr', template: { id: 't1', name: 'Employment verification', delivery: 'generated', includes_salary: false, fields: letter.fields },
  employee: { id: 'emp1', name: 'Jordan Lee', employee_number: 'E-001' }, requester: { id: 'usr_employee', name: 'Jordan Lee' }, answers: { purpose: 'Visa application' },
  manager_step: true, manager_decision: { by: 'Sam Patel', at }, decision: null, rejection_reason: null, delivered_at: null, document: null, created_at: at, version: 2,
  can: { decide: false, deliver: false, cancel: true, download: false },
};

function fakeSource(request: DocumentRequest, overrides: Partial<DocumentsSource> = {}): DocumentsSource {
  return {
    ...disconnectedDocumentsSource,
    templates: vi.fn(async () => [letter, retired]),
    requests: vi.fn(async () => page([request])),
    request: vi.fn(async () => request),
    submit: vi.fn(async () => ({ ...base, id: 'r9', number: 9 })),
    decide: vi.fn(async () => request),
    cancel: vi.fn(async () => request),
    deliver: vi.fn(async () => request),
    download: vi.fn(async () => undefined),
    saveTemplate: vi.fn(async () => letter),
    ...overrides,
  };
}

function setup(persona: keyof typeof personas, source: DocumentsSource) {
  const router = createMemoryRouter(createRoutes(), { initialEntries: ['/documents'] });
  return renderUi(
    <SessionProvider gateway={createMockGateway(0)} initial={personas[persona]!}>
      <WorkspaceProvider><DocumentsSourceProvider value={source}><RouterProvider router={router} /></DocumentsSourceProvider></WorkspaceProvider>
    </SessionProvider>,
  );
}
const openRequest = async () => {
  await userEvent.click((await screen.findAllByRole('button', { name: /Employment verification/ }))[0]!);
  return screen.findByRole('dialog');
};

describe('Document requests for employees', () => {
  it('lists only their requests, hides staff tabs and submits a request with answers', async () => {
    const source = fakeSource(base);
    const { container } = setup('employee@wingora.test', source);
    await screen.findAllByRole('button', { name: /Employment verification/ });
    expect(source.requests).toHaveBeenCalledWith(expect.objectContaining({ scope: 'mine' }), expect.anything());
    expect(screen.queryByRole('tab', { name: 'Approvals' })).toBeNull();
    expect(screen.queryByRole('tab', { name: 'Templates' })).toBeNull();
    expect(await axeViolations(container)).toEqual([]);

    await userEvent.click(screen.getByRole('button', { name: 'New request' }));
    const dialog = await screen.findByRole('dialog');
    expect(within(dialog).queryByRole('alert')).toBeNull();
    const type = within(dialog).getByRole('combobox', { name: /Document type/ });
    await waitFor(() => expect(within(type).getByRole('option', { name: 'Employment verification' })).toBeTruthy());
    expect(within(type).queryByRole('option', { name: 'Old reference letter' })).toBeNull();
    await userEvent.selectOptions(type, 't1');
    within(dialog).getByText('Your manager reviews this request before HR.');
    const submit = within(dialog).getByRole('button', { name: 'Submit request' });
    expect((submit as HTMLButtonElement).disabled).toBe(true);
    await userEvent.type(within(dialog).getByRole('textbox', { name: /Purpose/ }), 'Visa application');
    await userEvent.click(submit);
    await waitFor(() => expect(source.submit).toHaveBeenCalledWith('t1', { purpose: 'Visa application' }));
  });

  it('downloads a delivered document and offers no decision controls', async () => {
    const delivered = { ...base, status: 'delivered' as const, delivered_at: at, decision: { by: 'Priya Nair', at }, document: { name: 'letter.pdf', size: 1200, mime_type: 'application/pdf' },
      can: { decide: false, deliver: false, cancel: false, download: true } };
    const source = fakeSource(delivered);
    setup('employee@wingora.test', source);
    const drawer = await openRequest();
    await within(drawer).findByText('Visa application');
    expect(within(drawer).queryByRole('button', { name: 'Approve' })).toBeNull();
    expect(within(drawer).queryByRole('button', { name: 'Cancel request' })).toBeNull();
    await userEvent.click(within(drawer).getByRole('button', { name: 'Download document' }));
    expect(source.download).toHaveBeenCalledWith('r1');
  });
});

describe('Document requests for HR', () => {
  it('shows open approvals and requires a reason to reject', async () => {
    const source = fakeSource({ ...base, can: { decide: true, deliver: false, cancel: false, download: false } });
    const { container } = setup('hr@wingora.test', source);
    await userEvent.click(await screen.findByRole('tab', { name: 'Approvals' }));
    await waitFor(() => expect(source.requests).toHaveBeenCalledWith(expect.objectContaining({ scope: 'approvals', status: 'open' }), expect.anything()));
    expect(screen.queryByRole('tab', { name: 'Templates' })).toBeNull();
    await screen.findAllByText('Jordan Lee');
    expect(await axeViolations(container)).toEqual([]);

    const drawer = await openRequest();
    await userEvent.click(await within(drawer).findByRole('button', { name: 'Reject' }));
    const confirm = within(drawer).getByRole('button', { name: 'Reject request' });
    expect((confirm as HTMLButtonElement).disabled).toBe(true);
    await userEvent.type(within(drawer).getByRole('textbox', { name: /Reason for rejection/ }), 'Missing details');
    await userEvent.click(confirm);
    await waitFor(() => expect(source.decide).toHaveBeenCalledWith('r1', 'reject', 'Missing details', 2));
  });
});

describe('Document requests for owners', () => {
  it('delivers an approved salary letter only once the figure is typed', async () => {
    const approved: DocumentRequest = { ...base, status: 'approved', version: 3, template: { ...base.template, includes_salary: true }, decision: { by: 'Ana Ruiz', at },
      can: { decide: true, deliver: true, cancel: false, download: false } };
    const source = fakeSource(approved);
    setup('owner@wingora.test', source);
    await userEvent.click(await screen.findByRole('tab', { name: 'Approvals' }));
    const drawer = await openRequest();
    const deliver = await within(drawer).findByRole('button', { name: 'Deliver document' });
    expect(within(drawer).queryByRole('button', { name: 'Approve' })).toBeNull();
    within(drawer).getByRole('button', { name: 'Reject' });
    expect((deliver as HTMLButtonElement).disabled).toBe(true);
    await userEvent.type(within(drawer).getByRole('textbox', { name: /Salary as it should appear/ }), 'USD 90,000 per year');
    await userEvent.click(deliver);
    await waitFor(() => expect(source.deliver).toHaveBeenCalledWith('r1', 3, { salary: 'USD 90,000 per year' }));
  });

  it('creates a template with a question and shows allowed placeholders', async () => {
    const source = fakeSource(base);
    setup('owner@wingora.test', source);
    await userEvent.click(await screen.findByRole('tab', { name: 'Templates' }));
    await screen.findAllByRole('button', { name: 'Edit Old reference letter' });
    await userEvent.click(screen.getByRole('button', { name: 'New template' }));
    const dialog = await screen.findByRole('dialog');
    expect(within(dialog).queryByRole('alert')).toBeNull();
    await userEvent.type(within(dialog).getByRole('textbox', { name: /^Name/ }), 'Salary certificate');
    await userEvent.click(within(dialog).getByRole('button', { name: 'Add question' }));
    await userEvent.type(within(dialog).getByRole('textbox', { name: /^Key/ }), 'bank');
    await userEvent.type(within(dialog).getByRole('textbox', { name: /^Question/ }), 'Bank name');
    within(dialog).getByText('{{answers.bank}}');
    await userEvent.type(within(dialog).getByRole('textbox', { name: /Letter text/ }), 'Hello');
    await userEvent.click(within(dialog).getByRole('button', { name: 'Save template' }));
    await waitFor(() => expect(source.saveTemplate).toHaveBeenCalledWith(expect.objectContaining({
      name: 'Salary certificate', delivery: 'generated', body: 'Hello', fields: [{ key: 'bank', label: 'Bank name', type: 'text', required: false }],
    }), undefined));
  });
});
