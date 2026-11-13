import { act, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createMemoryRouter, RouterProvider } from 'react-router';
import { describe, expect, it } from 'vitest';
import { renderUi } from '@/test/render';
import { axeViolations } from '@/test/axe';
import { SessionProvider, safeRedirect } from '@/auth/session';
import { createMockGateway } from '@/mocks/mock-gateway';
import { personas } from '@/mocks/fixtures';
import { ErrorBoundary } from '@/shell/error-boundary';
import { WorkspaceProvider } from '@/shell/workspace';
import { createRoutes } from './routes';

function setup(path: string, persona?: keyof typeof personas) {
  const router = createMemoryRouter(createRoutes(), { initialEntries: [path] });
  const view = renderUi(
    <SessionProvider gateway={createMockGateway(0)} initial={persona ? personas[persona] : undefined}>
      <WorkspaceProvider><RouterProvider router={router} /></WorkspaceProvider>
    </SessionProvider>,
  );
  return { router, ...view };
}

/** The sidebar renders an expanded and a rail list (CSS picks one); inspect the expanded list. */
const navLinks = () => within(screen.getAllByRole('navigation', { name: 'Main navigation' })[0]!.firstElementChild as HTMLElement).getAllByRole('link').map((a) => a.textContent);

describe('authentication flow', () => {
  it('redirects signed-out users to sign-in and returns them after MFA', async () => {
    const { router } = setup('/payroll');
    expect(await screen.findByRole('heading', { name: 'Sign in to Wingora' })).toBeInTheDocument();

    await userEvent.click(screen.getByRole('button', { name: 'Sign in' }));
    expect(screen.getByLabelText(/Work email/)).toHaveAccessibleDescription('Enter your work email.');
    expect(screen.getByLabelText(/Work email/)).toHaveFocus();

    await userEvent.type(screen.getByLabelText(/Work email/), 'owner@wingora.test');
    await userEvent.type(screen.getByLabelText(/^Password/), 'wrong');
    await userEvent.click(screen.getByRole('button', { name: 'Sign in' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('The email or password is incorrect.');

    await userEvent.clear(screen.getByLabelText(/^Password/));
    await userEvent.type(screen.getByLabelText(/^Password/), 'correct horse');
    await userEvent.click(screen.getByRole('button', { name: 'Sign in' }));
    expect(await screen.findByRole('heading', { name: 'Two-step verification' })).toBeInTheDocument();

    await userEvent.type(screen.getByLabelText(/Authentication code/), '000000');
    await userEvent.click(screen.getByRole('button', { name: 'Verify' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('The code is invalid or has expired.');

    await userEvent.clear(screen.getByLabelText(/Authentication code/));
    await userEvent.type(screen.getByLabelText(/Authentication code/), '123456');
    await userEvent.click(screen.getByRole('button', { name: 'Verify' }));
    expect(await screen.findByRole('heading', { name: 'Payroll' })).toBeInTheDocument();
    expect(router.state.location.pathname).toBe('/payroll');
  });

  it('sends privileged users without MFA to enrolment before any module', async () => {
    const { router } = setup('/employees');
    await userEvent.type(await screen.findByLabelText(/Work email/), 'newhr@wingora.test');
    await userEvent.type(screen.getByLabelText(/^Password/), 'correct horse');
    await userEvent.click(screen.getByRole('button', { name: 'Sign in' }));
    expect(await screen.findByRole('heading', { name: 'Set up two-step verification' })).toBeInTheDocument();
    expect(router.state.location.pathname).toBe('/mfa/setup');
    expect(await screen.findByRole('img', { name: 'QR code for your authenticator app' })).toHaveAttribute('src', expect.stringMatching(/^data:image\/svg\+xml;base64,/));
    expect(screen.getByText('JBSW Y3DP EHPK 3PXP JBSW Y3DP EHPK 3PXP')).toBeInTheDocument();

    await userEvent.type(screen.getByLabelText(/Authentication code/), '000000');
    await userEvent.click(screen.getByRole('button', { name: 'Turn on two-step verification' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('The code is invalid or has expired.');

    await userEvent.clear(screen.getByLabelText(/Authentication code/));
    await userEvent.type(screen.getByLabelText(/Authentication code/), '123456');
    await userEvent.click(screen.getByRole('button', { name: 'Turn on two-step verification' }));
    expect(await screen.findByRole('heading', { name: 'Save your recovery codes' })).toBeInTheDocument();
    expect(within(screen.getByRole('list', { name: 'Save your recovery codes' })).getAllByRole('listitem')).toHaveLength(10);
    const unload = new Event('beforeunload', { cancelable: true });
    window.dispatchEvent(unload);
    expect(unload.defaultPrevented).toBe(true);
    expect(router.state.location.pathname).toBe('/mfa/setup');

    await userEvent.click(screen.getByRole('button', { name: "I've saved them, continue" }));
    await waitFor(() => expect(router.state.location.pathname).toBe('/employees'));
    expect(await screen.findAllByRole('navigation', { name: 'Main navigation' })).not.toHaveLength(0);
    expect(screen.queryByRole('heading', { name: 'Set up two-step verification' })).not.toBeInTheDocument();
  });

  it('MFA setup without a session goes back to sign-in', async () => {
    setup('/mfa/setup');
    expect(await screen.findByRole('heading', { name: 'Sign in to Wingora' })).toBeInTheDocument();
  });

  it('forgot password never reveals whether the account exists', async () => {
    setup('/forgot-password');
    await userEvent.type(await screen.findByLabelText(/Work email/), 'nobody@example.com');
    await userEvent.click(screen.getByRole('button', { name: 'Send reset link' }));
    expect(await screen.findByRole('status')).toHaveTextContent('If an account exists for nobody@example.com');
  });

  it('MFA page without a pending sign-in goes back to sign-in', async () => {
    setup('/mfa');
    expect(await screen.findByRole('heading', { name: 'Sign in to Wingora' })).toBeInTheDocument();
  });

  it('only redirects to same-origin paths', () => {
    expect(safeRedirect('/leave?x=1')).toBe('/leave?x=1');
    expect(safeRedirect('//evil.example')).toBe('/');
    expect(safeRedirect('/\\evil.example')).toBe('/');
    expect(safeRedirect('https://evil.example')).toBe('/');
  });
});

describe('permission-based navigation', () => {
  it('an Employee sees only permitted modules and gets 403 on others', async () => {
    setup('/dashboard', 'employee@wingora.test');
    expect(await screen.findByRole('heading', { name: /Welcome back, Jordan/ })).toBeInTheDocument();
    expect(navLinks()).toEqual(['Dashboard', 'Employees', 'Attendance', 'Leave', 'Payroll', 'Recruitment', 'Onboarding', 'Helpdesk', 'Documents']);
    expect(navLinks()).not.toContain('Settings');
  });

  it('direct navigation to an unauthorized module renders access denied', async () => {
    setup('/settings', 'employee@wingora.test');
    expect(await screen.findByRole('heading', { name: "You don't have access to this page" })).toBeInTheDocument();
    expect(screen.queryByRole('navigation', { name: 'Breadcrumb' })).not.toBeInTheDocument();
  });

  it('the Organization Owner sees all 16 modules', async () => {
    setup('/dashboard', 'owner@wingora.test');
    await screen.findByRole('heading', { name: /Welcome back/ });
    expect(navLinks()).toHaveLength(16);
  });

  it('global search lists only permitted modules and navigates with the keyboard', async () => {
    const { router } = setup('/dashboard', 'employee@wingora.test');
    await screen.findByRole('heading', { name: /Welcome back/ });
    await userEvent.keyboard('{Control>}k{/Control}');
    const dialog = screen.getByRole('dialog', { name: 'Search Wingora' });
    const options = within(dialog).getAllByRole('option').map((o) => o.textContent);
    expect(options).not.toContain('Settings');
    await userEvent.keyboard('lea');
    expect(within(dialog).getAllByRole('option')).toHaveLength(1);
    await userEvent.keyboard('{Enter}');
    expect(router.state.location.pathname).toBe('/leave');
    expect(await screen.findByRole('heading', { level: 1, name: 'Leave' })).toBeInTheDocument();
    expect(within(screen.getByRole('navigation', { name: 'Breadcrumb' })).getByText('Leave')).toHaveAttribute('aria-current', 'page');
  });

  it('notification center hides items for modules the user cannot open', async () => {
    setup('/dashboard', 'employee@wingora.test');
    await screen.findByRole('heading', { name: /Welcome back/ });
    // Only the Leave item is visible to an Employee, so the count must not include hidden items.
    await userEvent.click(screen.getByRole('button', { name: 'Notifications, 1 unread' }));
    const drawer = screen.getByRole('dialog', { name: 'Notifications' });
    expect(within(drawer).getByText('Leave request awaiting approval')).toBeInTheDocument();
    expect(within(drawer).queryByText('Review cycle opens Monday')).not.toBeInTheDocument();
    await userEvent.click(within(drawer).getByRole('button', { name: 'Mark all as read' }));
    expect(within(drawer).queryByText('New')).not.toBeInTheDocument();
  });
});

describe('shell', () => {
  it('switches legal entity and organization from radio menus', async () => {
    setup('/dashboard', 'owner@wingora.test');
    await screen.findByRole('heading', { name: /Welcome back/ });
    const [entityTrigger] = screen.getAllByRole('button', { name: 'Legal entity' });
    await userEvent.click(entityTrigger!);
    await userEvent.click(screen.getByRole('menuitemradio', { name: /App Wings FZ-LLC/ }));
    expect(screen.getByText(/App Wings LLC · App Wings FZ-LLC/)).toBeInTheDocument();
  });

  it('signs out from the profile menu', async () => {
    setup('/dashboard', 'hr@wingora.test');
    await screen.findByRole('heading', { name: /Welcome back/ });
    await userEvent.click(screen.getByRole('button', { name: 'Account' }));
    expect(screen.getByRole('menu')).toHaveTextContent('hr@wingora.test');
    await userEvent.click(screen.getByRole('menuitem', { name: 'Sign out' }));
    expect(await screen.findByRole('heading', { name: 'Sign in to Wingora' })).toBeInTheDocument();
  });

  it('renders not found for unknown paths', async () => {
    setup('/nope', 'hr@wingora.test');
    expect(await screen.findByRole('heading', { name: 'Page not found' })).toBeInTheDocument();
  });

  it('the dashboard has no axe violations', async () => {
    const { container } = setup('/dashboard', 'owner@wingora.test');
    await screen.findByRole('heading', { name: /Welcome back/ });
    expect(await axeViolations(container)).toEqual([]);
  });

  it('error boundary shows the application error page and recovers', async () => {
    let fail = true;
    function Boom() {
      if (fail) throw new Error('boom');
      return <p>Recovered</p>;
    }
    const spy = console.error;
    console.error = () => undefined;
    renderUi(<ErrorBoundary fallback={(reset) => <button type="button" onClick={reset}>Try again</button>}><Boom /></ErrorBoundary>);
    console.error = spy;
    fail = false;
    await act(async () => { await userEvent.click(screen.getByRole('button', { name: 'Try again' })); });
    expect(screen.getByText('Recovered')).toBeInTheDocument();
  });
});
