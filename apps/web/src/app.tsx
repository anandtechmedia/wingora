import { useMemo } from 'react';
import { createBrowserRouter, RouterProvider } from 'react-router';
import { ToastProvider } from '@/components/ui';
import { SessionProvider } from '@/auth/session';
import { I18nProvider } from '@/i18n/i18n';
import { createMockGateway } from '@/mocks/mock-gateway';
import { WorkspaceProvider } from '@/shell/workspace';
import { createRoutes } from './app/routes';

/** PROTOTYPE: the mock gateway is the only auth transport until `@wingora/api-client` exists. */
const gateway = createMockGateway();

export function App() {
  const router = useMemo(() => createBrowserRouter(createRoutes({ prototypeHint: true })), []);
  const locale = navigator.language.toLowerCase().startsWith('es') ? 'es-MX' : 'en-US';
  return (
    <I18nProvider locale={locale}>
      <ToastProvider>
        <SessionProvider gateway={gateway}>
          <WorkspaceProvider>
            <RouterProvider router={router} />
          </WorkspaceProvider>
        </SessionProvider>
      </ToastProvider>
    </I18nProvider>
  );
}
