import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createMemoryRouter, RouterProvider } from 'react-router';
import { describe, expect, it, vi } from 'vitest';
import { renderUi } from '@/test/render';
import { axeViolations } from '@/test/axe';
import { ApiError } from '@/api/http';
import { DashboardSourceProvider, disconnectedDashboardSource, type DashboardData, type DashboardSource } from '@/api/dashboard';
import { SessionProvider } from '@/auth/session';
import { createMockGateway } from '@/mocks/mock-gateway';
import { personas } from '@/mocks/fixtures';
import { WorkspaceProvider } from '@/shell/workspace';
import { createRoutes } from '@/app/routes';

/** Test doubles shaped like `GET /api/v1/dashboard` (backend tests assert the real payload). */
const organizationData: DashboardData = {
  scope: 'organization',
  filters: { country: null, legal_entity: null, from: '2025-07-01', to: '2026-06-15' },
  generated_at: '2026-06-15T10:00:00Z',
  widgets: {
    total_employees: { state: 'ok', value: 1284 },
    active_employees: { state: 'ok', value: 1200 },
    new_joiners: { state: 'ok', value: 37 },
    upcoming_birthdays: { state: 'ok', items: [{ employee_id: 'e1', name: 'Ana Ruiz', date: '2026-06-15', in_days: 0 }] },
    upcoming_anniversaries: { state: 'ok', items: [{ employee_id: 'e2', name: 'Omar Haddad', date: '2026-06-25', in_days: 10, years: 5 }] },
    workforce_trends: { state: 'ok', series: [{ month: '2026-05', headcount: 1250, hires: 20, exits: 4 }, { month: '2026-06', headcount: 1284, hires: 37, exits: 3 }] },
    pending_leave_approvals: { state: 'unavailable', module: 'leave' },
    contract_expirations: { state: 'unavailable', module: 'contracts' },
  },
};

function fakeSource(load: DashboardSource['load']): DashboardSource {
  return {
    load: vi.fn(load),
    legalEntities: vi.fn(async () => [
      { id: 'le_us', name: 'App Wings LLC', country_code: 'US' },
      { id: 'le_mx', name: 'App Wings México', country_code: 'MX' },
    ]),
  };
}

function setup(source: DashboardSource, persona: keyof typeof personas = 'hr@wingora.test') {
  const router = createMemoryRouter(createRoutes(), { initialEntries: ['/dashboard'] });
  return renderUi(
    <SessionProvider gateway={createMockGateway(0)} initial={personas[persona]}>
      <WorkspaceProvider>
        <DashboardSourceProvider value={source}><RouterProvider router={router} /></DashboardSourceProvider>
      </WorkspaceProvider>
    </SessionProvider>,
  );
}

describe('organization dashboard', () => {
  it('shows a skeleton, then figures exactly as returned by the API', async () => {
    let resolve!: (d: DashboardData) => void;
    setup(fakeSource(() => new Promise((r) => { resolve = r; })));
    expect(await screen.findByText('Loading dashboard…')).toBeInTheDocument();

    resolve(organizationData);
    const total = await screen.findByRole('heading', { name: 'Total employees' });
    expect(total.closest('section')).toHaveTextContent('1,284');
    expect(screen.getByRole('heading', { name: 'Active employees' }).closest('section')).toHaveTextContent('93% of total');
    expect(screen.getByRole('heading', { name: 'New joiners' }).closest('section')).toHaveTextContent('37');
    expect(screen.getByText('Ana Ruiz').closest('li')).toHaveTextContent('Today');
    expect(screen.getByText('Omar Haddad').closest('li')).toHaveTextContent('5 years');
    expect(screen.getByRole('heading', { name: 'Pending leave approvals' }).closest('section')).toHaveTextContent('Available when Leave is connected');
    expect(screen.getByRole('heading', { name: 'Upcoming contract expirations' }).closest('section')).toHaveTextContent('Available when Contracts is connected');
    expect(within(screen.getByRole('table')).getByRole('row', { name: /June 2026/ })).toHaveTextContent('1,2843');
    expect(screen.queryByRole('heading', { name: 'Absentees' })).not.toBeInTheDocument();
    expect(screen.getByText('Organization-wide figures')).toBeInTheDocument();
  });

  it('sends country, legal entity and date filters to the API', async () => {
    const source = fakeSource(async () => organizationData);
    setup(source);
    await screen.findByRole('heading', { name: 'Total employees' });

    await userEvent.selectOptions(screen.getByRole('combobox', { name: 'Country' }), 'MX');
    const entity = screen.getByRole('combobox', { name: 'Legal entity' });
    expect(within(entity).getAllByRole('option').map((o) => o.textContent)).toEqual(['All legal entities', 'App Wings México']);
    await userEvent.selectOptions(entity, 'le_mx');

    await waitFor(() => expect(source.load).toHaveBeenLastCalledWith({ country: 'MX', legalEntity: 'le_mx', from: '', to: '' }, expect.any(AbortSignal)));
    await userEvent.click(screen.getByRole('button', { name: 'Reset filters' }));
    await waitFor(() => expect(source.load).toHaveBeenLastCalledWith({ country: '', legalEntity: '', from: '', to: '' }, expect.any(AbortSignal)));
  });

  it('renders only what the server returned for a role without headcount access', async () => {
    setup(fakeSource(async () => ({ ...organizationData, scope: 'none', widgets: {} })), 'employee@wingora.test');
    expect(await screen.findByText('No organization figures for your role')).toBeInTheDocument();
    expect(screen.queryByRole('heading', { name: 'Total employees' })).not.toBeInTheDocument();
  });

  it('shows an error with retry, and recovers', async () => {
    let calls = 0;
    setup(fakeSource(async () => {
      calls += 1;
      if (calls === 1) throw new ApiError(500, null);
      return organizationData;
    }));
    expect(await screen.findByRole('alert')).toHaveTextContent('Dashboard figures couldn’t load');
    await userEvent.click(screen.getByRole('button', { name: 'Try again' }));
    expect(await screen.findByRole('heading', { name: 'Total employees' })).toBeInTheDocument();
  });

  it('never shows figures without the API', async () => {
    setup(disconnectedDashboardSource);
    expect(await screen.findByText('Live figures need the Wingora API')).toBeInTheDocument();
    expect(screen.queryByRole('heading', { name: 'Total employees' })).not.toBeInTheDocument();
  });

  it('has no axe violations with data', async () => {
    const { container } = setup(fakeSource(async () => organizationData));
    await screen.findByRole('heading', { name: 'Total employees' });
    expect(await axeViolations(container)).toEqual([]);
  });
});
