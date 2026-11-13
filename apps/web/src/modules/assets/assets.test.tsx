import { configure, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createMemoryRouter, RouterProvider } from 'react-router';
import { describe, expect, it, vi } from 'vitest';
import { renderUi } from '@/test/render';
import { axeViolations } from '@/test/axe';
import { AssetsSourceProvider, disconnectedAssetsSource, type Asset, type AssetCategory, type AssetsSource, type Assignment, type MaintenanceRecord } from '@/api/assets';
import { EmployeeSourceProvider, type EmployeeSource, type EmployeeSummary } from '@/api/employees';
import { SessionProvider } from '@/auth/session';
import { createMockGateway } from '@/mocks/mock-gateway';
import { personas } from '@/mocks/fixtures';
import { WorkspaceProvider } from '@/shell/workspace';
import { createRoutes } from '@/app/routes';

configure({ asyncUtilTimeout: 5000 });

/** Test doubles shaped like the Assets API (backend feature tests assert the real payloads). */
const page = <T,>(data: T[]) => ({ data, meta: { current_page: 1, last_page: 1, per_page: 25, total: data.length } });
const laptops: AssetCategory = { id: 'c1', name: 'Laptops', description: null, active: true, assets_count: 1, version: 1 };
const laptop: Asset = {
  id: 'a1', asset_tag: 'LT-001', name: 'MacBook Pro 14', serial_number: 'C02XYZ', category: { id: 'c1', name: 'Laptops' }, status: 'available', condition: 'good',
  purchased_on: '2027-01-02', purchase_cost: '2399.00', currency: 'USD', warranty_ends_on: '2030-01-02', notes: null, assigned_to: null, version: 3,
};
const jordan = { id: 'emp1', name: 'Jordan Lee', employee_number: 'E-001' };
const mine: Assignment = {
  id: 'as1', asset: { id: 'a1', asset_tag: 'LT-001', name: 'MacBook Pro 14', serial_number: 'C02XYZ', category: 'Laptops' },
  assigned_on: '2027-02-01', due_back_on: null, returned_on: null, condition_out: 'good', condition_in: null, notes: null, return_notes: null,
};
const repair: MaintenanceRecord = { id: 'm1', kind: 'repair', description: 'Battery swap', vendor: 'Apple', cost: '199.00', currency: 'USD', status: 'scheduled', scheduled_on: '2027-03-01', completed_on: null, created_at: null, version: 1 };

function fakeSource(asset: Asset, overrides: Partial<AssetsSource> = {}): AssetsSource {
  return {
    ...disconnectedAssetsSource,
    mine: vi.fn(async () => page([mine])),
    assets: vi.fn(async () => page([asset])),
    asset: vi.fn(async () => asset),
    history: vi.fn(async () => ({ assignments: [{ ...mine, asset: undefined, employee: jordan, returned_on: '2027-02-20', condition_in: 'fair' as const }], maintenance: [repair] })),
    categories: vi.fn(async () => [laptops]),
    saveAsset: vi.fn(async () => asset),
    setStatus: vi.fn(async () => asset),
    assign: vi.fn(async () => asset),
    returnAsset: vi.fn(async () => asset),
    addMaintenance: vi.fn(async () => repair),
    updateMaintenance: vi.fn(async () => repair),
    saveCategory: vi.fn(async () => laptops),
    ...overrides,
  };
}
const people = { list: vi.fn(async () => page([{ id: 'emp1', employee_number: 'E-001', first_name: 'Jordan', last_name: 'Lee', preferred_name: null } as unknown as EmployeeSummary])) } as unknown as EmployeeSource;

function setup(persona: keyof typeof personas, source: AssetsSource) {
  const router = createMemoryRouter(createRoutes(), { initialEntries: ['/assets'] });
  return renderUi(
    <SessionProvider gateway={createMockGateway(0)} initial={personas[persona]!}>
      <WorkspaceProvider><EmployeeSourceProvider value={people}><AssetsSourceProvider value={source}><RouterProvider router={router} /></AssetsSourceProvider></EmployeeSourceProvider></WorkspaceProvider>
    </SessionProvider>,
  );
}
const openAsset = async () => {
  await userEvent.click(await screen.findByRole('tab', { name: 'Registry' }));
  await userEvent.click((await screen.findAllByRole('button', { name: /MacBook Pro 14/ }))[0]!);
  return screen.findByRole('dialog');
};

describe('Assets for employees', () => {
  it('shows only their own assignments and no registry', async () => {
    const source = fakeSource(laptop);
    const { container } = setup('employee@wingora.test', source);
    expect((await screen.findAllByText(/MacBook Pro 14/)).length).toBeGreaterThan(0);
    expect(screen.queryByRole('tab', { name: 'Registry' })).toBeNull();
    expect(screen.queryByRole('tab', { name: 'Categories' })).toBeNull();
    expect(source.assets).not.toHaveBeenCalled();
    expect(await axeViolations(container)).toEqual([]);
  });

  it('explains when the API is not connected', async () => {
    setup('employee@wingora.test', disconnectedAssetsSource);
    expect(await screen.findByText('Assets is not connected')).toBeInTheDocument();
  });
});

describe('Assets for HR', () => {
  it('assigns an available asset to an employee with the current version', async () => {
    const source = fakeSource(laptop);
    setup('hr@wingora.test', source);
    const drawer = await openAsset();
    expect(await within(drawer).findByText('Battery swap')).toBeInTheDocument();
    expect(within(drawer).getByText('Jordan Lee')).toBeInTheDocument();
    await userEvent.click(within(drawer).getByRole('button', { name: 'Assign' }));
    const dialog = await screen.findByRole('dialog', { name: 'Assign LT-001' });
    const select = within(dialog).getByRole('combobox', { name: /Employee/ });
    await waitFor(() => expect(within(select).getByRole('option', { name: /Jordan Lee/ })).toBeTruthy());
    await userEvent.selectOptions(select, 'emp1');
    await userEvent.type(within(dialog).getByLabelText('Due back'), '2028-02-01');
    await userEvent.click(within(dialog).getByRole('button', { name: 'Assign' }));
    await waitFor(() => expect(source.assign).toHaveBeenCalledWith('a1', { employee_id: 'emp1', assigned_on: null, due_back_on: '2028-02-01', notes: null }, 3));
  });

  it('records a return with the condition for an assigned asset', async () => {
    const source = fakeSource({ ...laptop, status: 'assigned', assigned_to: jordan });
    setup('hr@wingora.test', source);
    const drawer = await openAsset();
    expect(within(drawer).queryByRole('button', { name: 'Assign' })).toBeNull();
    expect(within(drawer).queryByRole('button', { name: 'Add maintenance' })).toBeNull();
    await userEvent.click(await within(drawer).findByRole('button', { name: 'Record return' }));
    const dialog = await screen.findByRole('dialog', { name: 'Return LT-001' });
    await userEvent.selectOptions(within(dialog).getByRole('combobox', { name: /Condition on return/ }), 'damaged');
    await userEvent.click(within(dialog).getByRole('button', { name: 'Record return' }));
    await waitFor(() => expect(source.returnAsset).toHaveBeenCalledWith('a1', { condition: 'damaged', returned_on: null, notes: null }, 3));
  });

  it('moves maintenance forward and retires only after confirmation', async () => {
    const source = fakeSource(laptop);
    setup('hr@wingora.test', source);
    const drawer = await openAsset();
    await userEvent.click(await within(drawer).findByRole('button', { name: 'Start work' }));
    await waitFor(() => expect(source.updateMaintenance).toHaveBeenCalledWith('m1', 'in_progress', 1));
    await userEvent.click(within(drawer).getByRole('button', { name: 'Retire' }));
    const confirm = await screen.findByRole('alertdialog');
    expect(source.setStatus).not.toHaveBeenCalled();
    await userEvent.click(within(confirm).getByRole('button', { name: 'Retire' }));
    await waitFor(() => expect(source.setStatus).toHaveBeenCalledWith('a1', 'retired', 3));
  });

  it('creates a category', async () => {
    const source = fakeSource(laptop);
    setup('hr@wingora.test', source);
    await userEvent.click(await screen.findByRole('tab', { name: 'Categories' }));
    await userEvent.click(await screen.findByRole('button', { name: 'New category' }));
    const dialog = await screen.findByRole('dialog', { name: 'New category' });
    await userEvent.type(within(dialog).getByRole('textbox', { name: /Name/ }), 'Phones');
    await userEvent.click(within(dialog).getByRole('button', { name: 'Save' }));
    await waitFor(() => expect(source.saveCategory).toHaveBeenCalledWith({ name: 'Phones', description: null, active: true }, undefined, undefined));
  });
});
