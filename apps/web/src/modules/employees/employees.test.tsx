import { configure, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createMemoryRouter, RouterProvider } from 'react-router';
import { describe, expect, it, vi } from 'vitest';
import { renderUi } from '@/test/render';
import { axeViolations } from '@/test/axe';
import { ApiError } from '@/api/http';
import { disconnectedEmployeeSource, EmployeeSourceProvider, type EmployeeDetail, type EmployeeSource, type EmployeeSummary } from '@/api/employees';
import { SessionProvider } from '@/auth/session';
import { createMockGateway } from '@/mocks/mock-gateway';
import { personas } from '@/mocks/fixtures';
import { WorkspaceProvider } from '@/shell/workspace';
import { createRoutes } from '@/app/routes';

// The first test also pays for the lazy route chunk; CI runners can exceed the 1 s default.
configure({ asyncUtilTimeout: 5000 });

/** Test doubles shaped like the Employee Management API (backend feature tests assert the real payloads). */
const ana: EmployeeSummary = {
  id: 'emp_ana', employee_number: 'E-100', first_name: 'Ana', last_name: 'Ruiz', preferred_name: null, work_email: 'ana@example.com',
  job_title: 'Analyst', status: 'active', employment_type: 'full_time', hire_date: '2024-03-01', termination_date: null,
  department: { id: 'dep_fin', name: 'Finance' }, designation: null, manager: { id: 'emp_omar', name: 'Omar Haddad', job_title: 'Director' },
  legal_entity: { id: 'le_us', name: 'App Wings LLC', country_code: 'US' }, work_location: null,
};
const abilities = { update: true, view_personal: true, update_personal: true, view_sensitive: true, update_sensitive: true, view_records: true, manage_records: true };
const page = (data: EmployeeSummary[]) => ({ data, meta: { current_page: 1, last_page: 1, per_page: 25, total: data.length } });

function fakeSource(overrides: Partial<EmployeeSource> = {}): EmployeeSource {
  return {
    ...disconnectedEmployeeSource,
    list: vi.fn(async () => page([ana])),
    options: vi.fn(async () => ({ departments: [{ id: 'dep_fin', name: 'Finance' }], designations: [], legalEntities: [], workLocations: [] })),
    get: vi.fn(async () => ({ ...ana, abilities }) as EmployeeDetail),
    history: vi.fn(async () => ({ data: [{ id: 'h1', effective_date: '2025-01-01', change_type: 'transfer', changes: { department: { from: 'Sales', to: 'Finance' } }, note: 'Reorg', recorded_by: 'Priya Nair', recorded_at: null }], meta: { current_page: 1, last_page: 1, per_page: 100, total: 1 } })),
    records: vi.fn(async () => [{ id: 'r1', type: 'disciplinary' as const, severity: 'written_warning', title: 'Late', body: 'Repeated lateness', occurred_on: '2026-01-10', author: 'Priya Nair', created_at: null }]),
    ...overrides,
  };
}

function setup(source: EmployeeSource, path = '/employees', persona: keyof typeof personas = 'hr@wingora.test') {
  const router = createMemoryRouter(createRoutes(), { initialEntries: [path] });
  return renderUi(
    <SessionProvider gateway={createMockGateway(0)} initial={personas[persona]!}>
      <WorkspaceProvider><EmployeeSourceProvider value={source}><RouterProvider router={router} /></EmployeeSourceProvider></WorkspaceProvider>
    </SessionProvider>,
  );
}

describe('employee directory', () => {
  it('lists employees from the API and sends search and filters to the server', async () => {
    const source = fakeSource();
    const { container } = setup(source);
    expect((await screen.findAllByRole('link', { name: 'Ana Ruiz' }))[0]).toHaveAttribute('href', '/employees/emp_ana');
    expect(screen.getAllByText('Finance').length).toBeGreaterThan(0);

    await userEvent.type(screen.getByRole('searchbox', { name: 'Search employees' }), 'ruiz{Enter}');
    await waitFor(() => expect(source.list).toHaveBeenLastCalledWith(expect.objectContaining({ search: 'ruiz', page: 1 }), expect.any(AbortSignal)));
    await userEvent.selectOptions(screen.getByRole('combobox', { name: 'Status' }), 'terminated');
    await waitFor(() => expect(source.list).toHaveBeenLastCalledWith(expect.objectContaining({ status: 'terminated' }), expect.any(AbortSignal)));
    await userEvent.click(screen.getByRole('button', { name: 'Reset filters' }));
    await waitFor(() => expect(source.list).toHaveBeenLastCalledWith(expect.objectContaining({ search: '', status: '' }), expect.any(AbortSignal)));
    expect(await axeViolations(container)).toEqual([]);
  });

  it('shows no records and no actions without the API', async () => {
    setup(disconnectedEmployeeSource);
    expect(await screen.findByText('Employee data needs the Wingora API')).toBeInTheDocument();
    expect(screen.queryByRole('table')).not.toBeInTheDocument();
  });

  it('hides import, export and create for an employee', async () => {
    setup(fakeSource(), '/employees', 'employee@wingora.test');
    await screen.findAllByRole('link', { name: 'Ana Ruiz' });
    expect(screen.queryByRole('button', { name: 'Add employee' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Import CSV' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Export CSV' })).not.toBeInTheDocument();
  });

  it('shows server validation errors when creating an employee', async () => {
    const create = vi.fn(async () => { throw new ApiError(422, { errors: { employee_number: ['The employee number has already been taken.'] } }); });
    setup(fakeSource({ create }));
    await userEvent.click(await screen.findByRole('button', { name: 'Add employee' }));
    const dialog = await screen.findByRole('dialog', { name: 'Add employee' });
    await userEvent.type(within(dialog).getByRole('textbox', { name: /Employee number/ }), 'E-100');
    await userEvent.click(within(dialog).getByRole('button', { name: 'Save' }));
    expect(await within(dialog).findByText('The employee number has already been taken.')).toBeInTheDocument();
    expect(create).toHaveBeenCalledWith(expect.objectContaining({ employee_number: 'E-100' }));
  });

  it('checks a CSV import before running it and lists row errors', async () => {
    const importCsv = vi.fn(async () => ({ rows: 2, valid: 1, invalid: 1, dry_run: true, created: 0, errors: [{ line: 3, employee_number: 'E-2', errors: ['The last name field is required.'] }] }));
    setup(fakeSource({ importCsv }), '/employees', 'owner@wingora.test');
    await userEvent.click(await screen.findByRole('button', { name: 'Import CSV' }));
    const dialog = await screen.findByRole('dialog', { name: 'Import employees from CSV' });
    await userEvent.upload(within(dialog).getByLabelText('File'), new File(['employee_number,first_name,last_name\n'], 'staff.csv', { type: 'text/csv' }));
    await userEvent.click(within(dialog).getByRole('button', { name: 'Check file' }));
    expect(await within(dialog).findByText('1 of 2 rows are valid.')).toBeInTheDocument();
    expect(within(dialog).getByText(/The last name field is required/)).toBeInTheDocument();
    expect(importCsv).toHaveBeenCalledWith(expect.any(File), true);
    expect(within(dialog).queryByRole('button', { name: /^Import \d/ })).not.toBeInTheDocument();
  });
});

describe('employee profile', () => {
  it('shows tabs the server allows, including history and HR records', async () => {
    setup(fakeSource(), '/employees/emp_ana');
    expect(await screen.findByRole('heading', { name: /Ana Ruiz/ })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Omar Haddad' })).toHaveAttribute('href', '/employees/emp_omar');
    await userEvent.click(screen.getByRole('tab', { name: 'History' }));
    expect(await screen.findByText('Department: Sales → Finance')).toBeInTheDocument();
    await userEvent.click(screen.getByRole('tab', { name: 'Notes and disciplinary' }));
    expect(await screen.findByText('Repeated lateness')).toBeInTheDocument();
  });

  it('never offers personal or disciplinary tabs the server denies', async () => {
    const source = fakeSource({ get: vi.fn(async () => ({ ...ana, abilities: { ...abilities, update: false, view_personal: false, update_personal: false, view_sensitive: false, view_records: false, manage_records: false } })) });
    setup(source, '/employees/emp_ana', 'employee@wingora.test');
    await screen.findByRole('heading', { name: /Ana Ruiz/ });
    expect(screen.queryByRole('tab', { name: 'Notes and disciplinary' })).not.toBeInTheDocument();
    expect(screen.queryByRole('tab', { name: 'Personal' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Edit' })).not.toBeInTheDocument();
    expect(source.records).not.toHaveBeenCalled();
  });

  it('shows a form-level error when the server rejects a sixth emergency contact', async () => {
    const addContact = vi.fn(async () => { throw new ApiError(422, { detail: 'An employee may have at most 5 emergency contacts.' }); });
    setup(fakeSource({ contacts: vi.fn(async () => []), addContact }), '/employees/emp_ana');
    await userEvent.click(await screen.findByRole('tab', { name: 'Emergency contacts' }));
    await userEvent.click(await screen.findByRole('button', { name: 'Add contact' }));
    const dialog = await screen.findByRole('dialog', { name: 'Add contact' });
    await userEvent.type(within(dialog).getByRole('textbox', { name: /Name/ }), 'Sam');
    await userEvent.type(within(dialog).getByRole('textbox', { name: /Phone/ }), '+15550100');
    await userEvent.click(within(dialog).getByRole('button', { name: 'Save' }));
    expect(await within(dialog).findByRole('alert')).toHaveTextContent('An employee may have at most 5 emergency contacts.');
    expect(within(dialog).getByRole('option', { name: 'Spouse' })).toBeInTheDocument();
  });

  it('shows not found for an employee outside the user\'s scope', async () => {
    setup(fakeSource({ get: vi.fn(async () => { throw new ApiError(404, null); }) }), '/employees/emp_other');
    expect(await screen.findByText('This employee does not exist or you cannot view it.')).toBeInTheDocument();
  });
});
