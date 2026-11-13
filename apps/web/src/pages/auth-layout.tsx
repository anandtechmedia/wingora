import type { ReactNode } from 'react';
import { GlobeIcon, LockIcon, ShieldIcon, UsersIcon } from '@/components/ui';
import { useI18n } from '@/i18n/i18n';

export function AuthLayout({ title, subtitle, children, footer }: { title: string; subtitle?: ReactNode; children: ReactNode; footer?: ReactNode }) {
  const { t } = useI18n();
  return (
    <div className="grid min-h-dvh bg-canvas lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)]">
      <aside className="relative hidden flex-col justify-between overflow-hidden bg-nav-bg p-10 text-nav-fg lg:flex xl:p-14">
        <div aria-hidden="true" className="pointer-events-none absolute inset-0 bg-[radial-gradient(ellipse_at_top_right,var(--color-accent)_0%,transparent_60%)] opacity-30" />
        <div aria-hidden="true" className="pointer-events-none absolute inset-0 bg-[linear-gradient(var(--color-nav-fg)_1px,transparent_1px),linear-gradient(90deg,var(--color-nav-fg)_1px,transparent_1px)] bg-size-[48px_48px] opacity-[0.05]" />
        <div className="relative flex items-center gap-2.5">
          <span aria-hidden="true" className="inline-flex size-9 items-center justify-center rounded-md bg-accent text-md font-bold text-on-accent">W</span>
          <span className="text-lg font-semibold tracking-tight text-nav-active-fg">{t('app.name')}</span>
        </div>
        <div className="relative max-w-(--container-form)">
          <p className="text-xs font-semibold tracking-[0.18em] text-nav-fg-muted uppercase">{t('app.positioning')}</p>
          <p className="mt-4 text-3xl leading-tight font-semibold tracking-tight text-nav-active-fg">{t('app.tagline')}</p>
          <ul className="mt-10 space-y-4">
            {([['markets', GlobeIcon], ['access', UsersIcon], ['privacy', LockIcon]] as const).map(([key, Icon]) => (
              <li key={key} className="flex items-start gap-3 text-sm text-nav-fg">
                <span aria-hidden="true" className="inline-flex size-7 shrink-0 items-center justify-center rounded-full bg-nav-hover-bg text-nav-active-fg"><Icon size={14} /></span>
                <span className="pt-1">{t(`auth.point.${key}`)}</span>
              </li>
            ))}
          </ul>
        </div>
        <div className="relative flex items-center justify-between gap-4 border-t border-nav-hover-bg pt-6 text-xs text-nav-fg-muted">
          <p className="flex items-center gap-2"><ShieldIcon size={14} />{t('auth.securedBy')}</p>
          <p>{t('auth.copyright')}</p>
        </div>
      </aside>
      <main className="flex flex-col items-center justify-center px-4 py-10 sm:px-8">
        <div className="mb-8 flex items-center gap-2.5 lg:hidden">
          <span aria-hidden="true" className="inline-flex size-9 items-center justify-center rounded-md bg-primary text-md font-bold text-on-primary">W</span>
          <span className="flex flex-col leading-none">
            <span className="text-lg font-semibold tracking-tight text-fg">{t('app.name')}</span>
            <span className="mt-1 text-2xs text-fg-muted">{t('app.tagline')}</span>
          </span>
        </div>
        <div className="w-full max-w-(--container-modal-sm)">
          <h1 className="text-2xl font-semibold tracking-tight text-fg">{title}</h1>
          {subtitle && <p className="mt-2 text-sm text-fg-secondary">{subtitle}</p>}
          <div className="mt-8">{children}</div>
          {footer && <div className="mt-8 border-t border-line pt-6 text-sm text-fg-secondary">{footer}</div>}
        </div>
      </main>
    </div>
  );
}

/** Announced, non-colour-only form-level error. */
export function FormAlert({ children }: { children: ReactNode }) {
  return (
    <div role="alert" className="mb-5 rounded-md border border-danger-line bg-danger-subtle px-3 py-2.5 text-sm text-danger-fg">{children}</div>
  );
}
