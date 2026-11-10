import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { renderUi } from '@/test/render';
import { axeViolations } from '@/test/axe';
import { DesignSystemPage } from '@/design-system/design-system-page';
import { AppShell } from './app-shell';

describe('AppShell', () => {
  it('provides a skip link, labelled navigation with the current page, and a main landmark', () => {
    renderUi(<AppShell active="#time-off"><p>Content</p></AppShell>);
    expect(screen.getByRole('link', { name: 'Skip to main content' })).toHaveAttribute('href', '#main-content');
    const nav = screen.getAllByRole('navigation', { name: 'Main navigation' })[0]!;
    expect(within(nav).getAllByRole('link', { name: 'Time Off' })[0]).toHaveAttribute('aria-current', 'page');
    expect(screen.getByRole('main')).toHaveAttribute('id', 'main-content');
  });

  it('opens mobile navigation in a drawer', async () => {
    renderUi(<AppShell><p>Content</p></AppShell>);
    await userEvent.click(screen.getByRole('button', { name: 'Open navigation' }));
    const drawer = screen.getByRole('dialog', { name: 'Wingora' });
    expect(within(drawer).getByRole('navigation', { name: 'Main navigation' })).toBeInTheDocument();
    await userEvent.keyboard('{Escape}');
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
  });

  it('localizes the shell', () => {
    renderUi(<AppShell><p>Contenido</p></AppShell>, { locale: 'es-MX' });
    expect(screen.getByRole('link', { name: 'Ir al contenido principal' })).toBeInTheDocument();
    expect(screen.getAllByRole('link', { name: 'Ausencias' }).length).toBeGreaterThan(0);
  });

  it('the full design-system page has no axe violations', async () => {
    const { container } = renderUi(<AppShell><DesignSystemPage locale="en-US" onLocaleChange={() => undefined} /></AppShell>);
    expect(await axeViolations(container)).toEqual([]);
  }, 30000);
});
