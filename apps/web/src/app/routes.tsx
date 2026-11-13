import { lazy, Suspense, type ComponentType, type LazyExoticComponent } from 'react';
import { Navigate, type RouteObject } from 'react-router';
import { canAccessModule, homePath, modules, type ModuleDefinition, type ModuleId } from '@wingora/permissions';
import { PublicOnly, RequireAuth, useSession } from '@/auth/session';
import { AccessDeniedPage, NotFoundPage, RouteErrorPage } from '@/pages/error-pages';
import { ForgotPasswordPage } from '@/pages/forgot-password-page';
import { LoginPage } from '@/pages/login-page';
import { MfaPage } from '@/pages/mfa-page';
import { AppLayout } from '@/shell/app-layout';
import { LoadingState } from '@/components/ui';

type ModuleComponent = LazyExoticComponent<ComponentType<{ moduleId: ModuleId }>>;

/** Each module is its own chunk, fetched on first visit. Unbuilt modules share the placeholder chunk. */
const comingSoon: ModuleComponent = lazy(() => import('@/modules/coming-soon'));
const screens: Partial<Record<ModuleId, ModuleComponent>> = {
  dashboard: lazy(() => import('@/modules/dashboard')),
  employees: lazy(() => import('@/modules/employees')),
  attendance: lazy(() => import('@/modules/attendance')),
  leave: lazy(() => import('@/modules/leave')),
  payroll: lazy(() => import('@/modules/payroll')),
  recruitment: lazy(() => import('@/modules/recruitment')),
  onboarding: lazy(() => import('@/modules/onboarding')),
  helpdesk: lazy(() => import('@/modules/helpdesk')),
  documents: lazy(() => import('@/modules/documents')),
  assets: lazy(() => import('@/modules/assets')),
  projects: lazy(() => import('@/modules/projects')),
  billing: lazy(() => import('@/modules/billing')),
  platform: lazy(() => import('@/modules/platform')),
};
/** Public careers site: outside the signed-in shell, no session required. */
const CareersPage = lazy(() => import('@/pages/careers-page'));
const DesignSystem = lazy(() => import('@/design-system/design-system-route'));

/** Route guard: renders 403 in place (URL kept) when the user lacks the module's permission. */
/** `/` sends each user to the first module they may open (platform staff have no organization dashboard). */
function Home() {
  const { permissions } = useSession();
  return <Navigate to={homePath(permissions)} replace />;
}

function ModuleRoute({ module }: { module: ModuleDefinition }) {
  const { permissions } = useSession();
  if (!canAccessModule(permissions, module)) return <AccessDeniedPage />;
  const Screen = screens[module.id] ?? comingSoon;
  return <Screen moduleId={module.id} />;
}

export function createRoutes({ prototypeHint = false }: { prototypeHint?: boolean } = {}): RouteObject[] {
  return [
    { path: '/login', element: <PublicOnly><LoginPage prototypeHint={prototypeHint} /></PublicOnly>, errorElement: <RouteErrorPage /> },
    { path: '/forgot-password', element: <PublicOnly><ForgotPasswordPage /></PublicOnly>, errorElement: <RouteErrorPage /> },
    { path: '/mfa', element: <MfaPage />, errorElement: <RouteErrorPage /> },
    { path: '/careers/:site', element: <Suspense fallback={<LoadingState className="py-24" />}><CareersPage /></Suspense>, errorElement: <RouteErrorPage /> },
    { path: '/careers/:site/:job', element: <Suspense fallback={<LoadingState className="py-24" />}><CareersPage /></Suspense>, errorElement: <RouteErrorPage /> },
    {
      path: '/',
      element: <RequireAuth><AppLayout /></RequireAuth>,
      errorElement: <RouteErrorPage />,
      children: [
        { index: true, element: <Home /> },
        ...modules.map((m) => ({ path: m.path, element: <ModuleRoute module={m} /> })),
        ...modules.filter((m) => m.id === 'employees').map((m) => ({ path: `${m.path}/:recordId`, element: <ModuleRoute module={m} /> })),
        { path: 'design-system', element: <DesignSystem /> },
        { path: 'access-denied', element: <AccessDeniedPage /> },
        { path: '*', element: <NotFoundPage /> },
      ],
    },
  ];
}
