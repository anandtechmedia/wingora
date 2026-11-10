import type { ReactNode } from 'react';
import { isRouteErrorResponse, Link, useNavigate, useRouteError } from 'react-router';
import { AlertTriangleIcon, Button, buttonClasses, CompassIcon, LockIcon } from '@/components/ui';
import { useI18n } from '@/i18n/i18n';

function ErrorPage({ code, icon, title, description, actions, fullPage }: { code: number; icon: ReactNode; title: string; description: string; actions: ReactNode; fullPage?: boolean }) {
  const { t } = useI18n();
  return (
    <div className={fullPage ? 'flex min-h-dvh items-center justify-center bg-canvas px-4' : 'flex min-h-[60dvh] items-center justify-center py-12'}>
      <div className="flex max-w-(--container-form) flex-col items-center text-center">
        <span className="mb-5 inline-flex size-12 items-center justify-center rounded-full bg-surface-muted text-fg-secondary">{icon}</span>
        <p className="text-xs font-semibold tracking-wide text-accent-fg uppercase">{t('error.code', { code })}</p>
        <h1 className="mt-2 text-2xl font-semibold tracking-tight text-fg">{title}</h1>
        <p className="mt-2 text-sm text-fg-secondary">{description}</p>
        <div className="mt-6 flex flex-wrap justify-center gap-3">{actions}</div>
      </div>
    </div>
  );
}

function DashboardLink({ variant = 'primary' }: { variant?: 'primary' | 'secondary' }) {
  const { t } = useI18n();
  return <Link to="/dashboard" className={buttonClasses(variant, 'md')}>{t('error.goToDashboard')}</Link>;
}

export function AccessDeniedPage() {
  const { t } = useI18n();
  return <ErrorPage code={403} icon={<LockIcon size={22} />} title={t('error.forbiddenTitle')} description={t('error.forbiddenDescription')} actions={<DashboardLink />} />;
}

export function NotFoundPage({ fullPage }: { fullPage?: boolean }) {
  const { t } = useI18n();
  const navigate = useNavigate();
  return (
    <ErrorPage fullPage={fullPage} code={404} icon={<CompassIcon size={22} />} title={t('error.notFoundTitle')} description={t('error.notFoundDescription')}
      actions={<><Button variant="secondary" onClick={() => navigate(-1)}>{t('error.goBack')}</Button><DashboardLink /></>} />
  );
}

/** Never renders error details: they can leak internals. */
export function AppErrorPage({ onRetry, fullPage }: { onRetry?: () => void; fullPage?: boolean }) {
  const { t } = useI18n();
  return (
    <ErrorPage fullPage={fullPage} code={500} icon={<AlertTriangleIcon size={22} />} title={t('error.appTitle')} description={t('error.appDescription')}
      actions={<><Button variant="secondary" onClick={onRetry ?? (() => window.location.reload())}>{onRetry ? t('ui.retry') : t('error.reload')}</Button><DashboardLink variant="primary" /></>} />
  );
}

/** Router `errorElement`: loader, lazy-import and render errors outside the in-layout boundary. */
export function RouteErrorPage() {
  const error = useRouteError();
  if (import.meta.env.DEV) console.error(error);
  if (isRouteErrorResponse(error) && error.status === 404) return <NotFoundPage fullPage />;
  return <AppErrorPage fullPage />;
}
