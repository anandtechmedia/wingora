import { useMemo } from 'react';
import { createBrowserRouter, RouterProvider } from 'react-router';
import { ToastProvider } from '@/components/ui';
import { SessionProvider } from '@/auth/session';
import { I18nProvider } from '@/i18n/i18n';
import { DashboardSourceProvider, disconnectedDashboardSource, httpDashboardSource } from '@/api/dashboard';
import { createLiveGateway } from '@/auth/live-gateway';
import { createMockGateway } from '@/mocks/mock-gateway';
import { WorkspaceProvider } from '@/shell/workspace';
import { createRoutes } from './app/routes';

/**
 * `VITE_API_MODE=live` signs in against the Laravel API and loads real dashboard figures.
 * Anything else is the visual prototype: mock sign-in and no figures (never invented ones).
 */
const live = import.meta.env.VITE_API_MODE === 'live';
const gateway = live ? createLiveGateway() : createMockGateway();
const dashboardSource = live ? httpDashboardSource : disconnectedDashboardSource;

export function App() {
  const router = useMemo(() => createBrowserRouter(createRoutes({ prototypeHint: !live })), []);
  const locale = navigator.language.toLowerCase().startsWith('es') ? 'es-MX' : 'en-US';
  return (
    <I18nProvider locale={locale}>
      <ToastProvider>
        <SessionProvider gateway={gateway}>
          <WorkspaceProvider>
            <DashboardSourceProvider value={dashboardSource}>
              <RouterProvider router={router} />
            </DashboardSourceProvider>
          </WorkspaceProvider>
        </SessionProvider>
      </ToastProvider>
    </I18nProvider>
  );
}
