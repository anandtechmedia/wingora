import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createMemoryRouter, RouterProvider } from 'react-router';
import { describe, expect, it, vi } from 'vitest';
import { renderUi } from '@/test/render';
import { axeViolations } from '@/test/axe';
import { SessionProvider } from '@/auth/session';
import { AuthError, type AuthGateway } from '@/auth/types';
import { createMockGateway } from '@/mocks/mock-gateway';
import { WorkspaceProvider } from '@/shell/workspace';
import { createRoutes } from '@/app/routes';

function setup(path: string, gateway: AuthGateway = createMockGateway(0)) {
  const router = createMemoryRouter(createRoutes(), { initialEntries: [path] });
  return { router, ...renderUi(<SessionProvider gateway={gateway}><WorkspaceProvider><RouterProvider router={router} /></WorkspaceProvider></SessionProvider>) };
}

const enforced = (): AuthGateway => ({
  ...createMockGateway(0),
  organizationSignIn: vi.fn(async (slug: string) => ({ slug, name: 'Acme', branding: { name: 'Acme People', color: '#0B5394', logo_url: '/logo.png' }, sso: { enabled: true, enforced: true } })),
});

describe('organization sign-in', { timeout: 20_000 }, () => {
  it('shows the organization page with single sign-on next to the password form', async () => {
    const { container } = setup('/login/demo');
    expect(await screen.findByRole('heading', { name: 'Sign in to Demo Company' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Sign in with SSO' })).toBeInTheDocument();
    expect(screen.getByLabelText(/Work email/)).toBeInTheDocument();
    expect(await axeViolations(container)).toEqual([]);
  });

  it('applies the brand and hides passwords when SSO is required, except for the owner', async () => {
    setup('/login/acme', enforced());
    expect(await screen.findByRole('heading', { name: 'Sign in to Acme People' })).toBeInTheDocument();
    expect(document.documentElement.style.getPropertyValue('--color-accent')).toBe('#0B5394');
    expect(screen.queryByLabelText(/Work email/)).not.toBeInTheDocument();
    await userEvent.click(screen.getByRole('button', { name: /Organization owner\? Sign in with a password/ }));
    expect(screen.getByLabelText(/Work email/)).toBeInTheDocument();
  });

  it('explains when an organization does not exist or SSO failed', async () => {
    setup('/login/nope?sso_error=1');
    expect(await screen.findByText(/couldn't find that organization/)).toBeInTheDocument();
    expect(screen.getByText(/Single sign-on didn't complete/)).toBeInTheDocument();
  });

  it('finds an organization by ID from the general sign-in page', async () => {
    const { router } = setup('/login');
    await userEvent.click(await screen.findByRole('button', { name: 'Sign in with single sign-on (SSO)' }));
    await userEvent.type(screen.getByLabelText(/Organization ID/), 'Demo');
    await userEvent.click(screen.getByRole('button', { name: 'Continue' }));
    await waitFor(() => expect(router.state.location.pathname).toBe('/login/demo'));
  });

  it('tells password users when their organization requires SSO', async () => {
    const gateway = { ...createMockGateway(0), signIn: vi.fn(async () => { throw new AuthError('sso_required'); }) };
    setup('/login', gateway);
    await userEvent.type(await screen.findByLabelText(/Work email/), 'hr@acme.test');
    await userEvent.type(screen.getByLabelText(/^Password/), 'secret');
    await userEvent.click(screen.getByRole('button', { name: 'Sign in' }));
    expect(await screen.findByText(/Your organization signs in with single sign-on/)).toBeInTheDocument();
  });

  it('rebuilds the session after the identity provider sends the user back', async () => {
    const gateway = createMockGateway(0);
    await gateway.signIn('employee@wingora.test', 'pw');
    const { router } = setup('/sso/complete', gateway);
    await waitFor(() => expect(router.state.location.pathname).not.toBe('/sso/complete'));
    expect(router.state.location.pathname).not.toMatch(/^\/login/);
  });
});
