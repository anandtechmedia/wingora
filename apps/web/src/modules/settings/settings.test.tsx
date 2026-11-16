import { configure, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createMemoryRouter, RouterProvider } from 'react-router';
import { describe, expect, it, vi } from 'vitest';
import { renderUi } from '@/test/render';
import { axeViolations } from '@/test/axe';
import { disconnectedSettingsSource, SettingsSourceProvider, type SettingsSource, type TenantUser } from '@/api/settings';
import { SessionProvider } from '@/auth/session';
import { createMockGateway } from '@/mocks/mock-gateway';
import { personas } from '@/mocks/fixtures';
import { WorkspaceProvider } from '@/shell/workspace';
import { createRoutes } from '@/app/routes';

configure({ asyncUtilTimeout: 5000 });

/** Test doubles shaped like the organization, user and account APIs (backend feature tests assert the real payloads). */
const page = <T,>(data: T[]) => ({ data, meta: { current_page: 1, last_page: 1, per_page: 25, total: data.length } });
const org = { id: 'org1', name: 'Demo Company', country_code: 'US', status: 'active', mfa_required: false };
const owner: TenantUser = { id: 'usr_owner', name: 'Alex Morgan', email: 'owner@wingora.test', status: 'active', roles: ['organization-owner'], mfa_enabled: true, last_login_at: null };
const hr: TenantUser = { id: 'usr_hr', name: 'Priya Nair', email: 'hr@wingora.test', status: 'active', roles: ['hr-manager'], mfa_enabled: true, last_login_at: '2027-01-10T10:00:00Z' };

function fakeSource(overrides: Partial<SettingsSource> = {}): SettingsSource {
  return {
    ...disconnectedSettingsSource,
    organization: vi.fn(async () => org),
    updateOrganization: vi.fn(async (name: string) => ({ ...org, name })),
    updateSecurity: vi.fn(async (mfa_required: boolean) => ({ ...org, mfa_required })),
    users: vi.fn(async () => page([owner, hr])),
    updateRoles: vi.fn(async (_id: string, roles: string[]) => ({ ...hr, roles })),
    updateStatus: vi.fn(async () => ({ ...hr, status: 'disabled' })),
    legalEntities: vi.fn(async () => [{ id: 'le1', name: 'Demo US', legal_name: 'Demo Company Inc.', country_code: 'US', subdivision_code: 'US-NY', currency_code: 'USD', default_language_code: 'en', address_line1: null, city: null, postal_code: null, status: 'active' as const }]),
    workLocations: vi.fn(async () => []),
    tokens: vi.fn(async () => []),
    regenerateRecoveryCodes: vi.fn(async () => Array.from({ length: 10 }, (_, i) => `NEW-${i}`)),
    ...overrides,
  };
}

function setup(persona: keyof typeof personas, source: SettingsSource) {
  const router = createMemoryRouter(createRoutes(), { initialEntries: ['/settings'] });
  return renderUi(
    <SessionProvider gateway={createMockGateway(0)} initial={personas[persona]!}>
      <WorkspaceProvider><SettingsSourceProvider value={source}><RouterProvider router={router} /></SettingsSourceProvider></WorkspaceProvider>
    </SessionProvider>,
  );
}

describe('Settings', { timeout: 20_000 }, () => {
  it('saves branding, refuses unreadable colors and uploads a logo', async () => {
    const source = fakeSource({
      organization: vi.fn(async () => ({ ...org, slug: 'demo', branding: { name: null, color: null, logo_url: null } })),
      updateBranding: vi.fn(async () => ({ ...org, branding: { name: 'Demo People', color: '#0B5394', logo_url: null } })),
      uploadLogo: vi.fn(async () => org),
    });
    setup('owner@wingora.test', source);
    await userEvent.type(await screen.findByLabelText(/Brand name/), 'Demo People');
    await userEvent.type(screen.getByLabelText(/Brand color/), '#0B53');
    expect(screen.getByText('Enter # followed by 6 hex digits.')).toBeInTheDocument();
    await userEvent.type(screen.getByLabelText(/Brand color/), '94');
    await userEvent.click(screen.getByRole('button', { name: 'Save branding' }));
    await waitFor(() => expect(source.updateBranding).toHaveBeenCalledWith({ brand_name: 'Demo People', brand_color: '#0B5394' }));
    expect(screen.getByText(/\/login\/demo$/)).toBeInTheDocument();
    await userEvent.upload(screen.getByLabelText(/Upload logo/), new File(['png'], 'logo.png', { type: 'image/png' }));
    await waitFor(() => expect(source.uploadLogo).toHaveBeenCalled());
  });

  it('configures single sign-on without ever showing the saved secret', async () => {
    const saved = { redirect_uri: 'https://app.example/api/v1/auth/sso/callback', connection: { issuer: 'https://idp.example.com', client_id: 'cid', has_client_secret: true, allowed_domains: ['example.com'], enabled: true, enforced: false, version: 3, updated_at: null } };
    const source = fakeSource({ sso: vi.fn(async () => saved), saveSso: vi.fn(async () => saved), deleteSso: vi.fn(async () => ({ ...saved, connection: null })) });
    setup('owner@wingora.test', source);
    expect(await screen.findByText(saved.redirect_uri)).toBeInTheDocument();
    expect(screen.getByLabelText(/Client secret/)).toHaveValue('');
    await userEvent.click(screen.getByRole('checkbox', { name: /Require single sign-on/ }));
    const form = screen.getByLabelText(/Issuer URL/).closest('form') as HTMLElement;
    await userEvent.click(within(form).getByRole('button', { name: 'Save connection' }));
    await waitFor(() => expect(source.saveSso).toHaveBeenCalledWith({ issuer: 'https://idp.example.com', client_id: 'cid', client_secret: null, allowed_domains: ['example.com'], enabled: true, enforced: true, version: 3 }));
    await userEvent.click(within(form).getByRole('button', { name: 'Remove connection' }));
    await userEvent.click(within(await screen.findByRole('alertdialog')).getByRole('button', { name: 'Remove connection' }));
    await waitFor(() => expect(source.deleteSso).toHaveBeenCalled());
  });

  it('renames the organization and turns on organization-wide two-step verification', async () => {
    const source = fakeSource();
    const { container } = setup('owner@wingora.test', source);
    const name = await screen.findByLabelText(/Organization name/);
    expect(await axeViolations(container)).toEqual([]);
    await userEvent.clear(name);
    await userEvent.type(name, 'Demo Holdings');
    await userEvent.click(screen.getByRole('button', { name: 'Save' }));
    await waitFor(() => expect(source.updateOrganization).toHaveBeenCalledWith('Demo Holdings'));
    await userEvent.click(screen.getByRole('checkbox', { name: /Require two-step verification for everyone/ }));
    await waitFor(() => expect(source.updateSecurity).toHaveBeenCalledWith(true));
  });

  it('edits another user\'s roles but never your own', async () => {
    const source = fakeSource();
    setup('owner@wingora.test', source);
    await userEvent.click(await screen.findByRole('tab', { name: 'Users and roles' }));
    expect((await screen.findAllByText('Priya Nair')).length).toBeGreaterThan(0);
    expect(screen.queryByRole('button', { name: 'Edit roles for Alex Morgan' })).not.toBeInTheDocument();
    await userEvent.click(screen.getAllByRole('button', { name: 'Edit roles for Priya Nair' })[0]!);
    const dialog = await screen.findByRole('dialog');
    await userEvent.click(within(dialog).getByRole('checkbox', { name: 'Auditor' }));
    await userEvent.click(within(dialog).getByRole('button', { name: 'Save' }));
    await waitFor(() => expect(source.updateRoles).toHaveBeenCalledWith('usr_hr', ['hr-manager', 'auditor']));
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());

    await userEvent.click(screen.getAllByRole('button', { name: 'Disable Priya Nair' })[0]!);
    const confirm = await screen.findByRole('alertdialog', { name: 'Disable Priya Nair?' });
    expect(source.updateStatus).not.toHaveBeenCalled();
    await userEvent.click(within(confirm).getByRole('button', { name: 'Disable' }));
    await waitFor(() => expect(source.updateStatus).toHaveBeenCalledWith('usr_hr', 'disabled'));
  });

  it('lists legal entities and regenerates recovery codes with an authenticator code', async () => {
    const source = fakeSource();
    setup('owner@wingora.test', source);
    await userEvent.click(await screen.findByRole('tab', { name: 'Entities and locations' }));
    expect((await screen.findAllByText('Demo Company Inc.')).length).toBeGreaterThan(0);
    await userEvent.click(screen.getByRole('tab', { name: 'My account' }));
    await userEvent.type(await screen.findByLabelText(/Authentication code/), '123456');
    await userEvent.click(screen.getByRole('button', { name: 'Get new recovery codes' }));
    await waitFor(() => expect(source.regenerateRecoveryCodes).toHaveBeenCalledWith('123456'));
    expect(within(await screen.findByRole('list', { name: 'New recovery codes' })).getAllByRole('listitem')).toHaveLength(10);
  });

  it('is not available to employees', async () => {
    setup('employee@wingora.test', fakeSource());
    expect(await screen.findByRole('heading', { name: "You don't have access to this page" })).toBeInTheDocument();
  });
});
