import type { ReactNode } from 'react';
import { cn } from '@/lib/cn';
import { useI18n } from '@/i18n/i18n';
import { Button } from './button';
import { AlertTriangleIcon, InboxIcon, RefreshIcon, Spinner } from './icons';

export function EmptyState({ title, description, icon, action, className, headingLevel = 3 }: {
  title: ReactNode; description?: ReactNode; icon?: ReactNode; action?: ReactNode; className?: string;
  /** Match the page outline (e.g. 2 when the state sits directly under the page h1). */
  headingLevel?: 2 | 3 | 4;
}) {
  const Heading = `h${headingLevel}` as const;
  return (
    <div className={cn('flex flex-col items-center px-6 py-12 text-center', className)}>
      <span className="mb-4 inline-flex size-12 items-center justify-center rounded-full bg-surface-muted text-fg-muted" aria-hidden="true">
        {icon ?? <InboxIcon size={22} />}
      </span>
      <Heading className="text-md font-semibold text-fg">{title}</Heading>
      {description && <p className="mt-1 max-w-(--container-form) text-sm text-fg-secondary">{description}</p>}
      {action && <div className="mt-5">{action}</div>}
    </div>
  );
}

export function Skeleton({ className }: { className?: string }) {
  return <span aria-hidden="true" className={cn('block animate-pulse rounded-md bg-surface-muted', className)} />;
}

/** Announces loading politely; the visual is a spinner or content-shaped skeleton rows. */
export function LoadingState({ label, variant = 'spinner', rows = 3, className }: {
  label?: string; variant?: 'spinner' | 'skeleton'; rows?: number; className?: string;
}) {
  const { t } = useI18n();
  const text = label ?? t('ui.loading');
  return (
    <div role="status" aria-live="polite" className={cn(variant === 'spinner' ? 'flex flex-col items-center gap-3 py-12 text-fg-muted' : 'flex flex-col gap-3 p-4', className)}>
      {variant === 'spinner' ? (
        <>
          <Spinner size={24} className="text-accent" />
          <span className="text-sm">{text}</span>
        </>
      ) : (
        <>
          <span className="sr-only">{text}</span>
          {Array.from({ length: rows }, (_, i) => (
            <div key={i} className="flex items-center gap-4">
              <Skeleton className="size-8 rounded-full" />
              <Skeleton className="h-3 flex-1" />
              <Skeleton className="hidden h-3 w-24 sm:block" />
            </div>
          ))}
        </>
      )}
    </div>
  );
}

export function ErrorState({ title, description, onRetry, className, headingLevel = 3 }: {
  title?: ReactNode; description?: ReactNode; onRetry?: () => void; className?: string; headingLevel?: 2 | 3 | 4;
}) {
  const Heading = `h${headingLevel}` as const;
  const { t } = useI18n();
  return (
    <div role="alert" className={cn('flex flex-col items-center px-6 py-12 text-center', className)}>
      <span className="mb-4 inline-flex size-12 items-center justify-center rounded-full bg-danger-subtle text-danger-fg" aria-hidden="true">
        <AlertTriangleIcon size={22} />
      </span>
      <Heading className="text-md font-semibold text-fg">{title ?? t('ui.error.title')}</Heading>
      <p className="mt-1 max-w-(--container-form) text-sm text-fg-secondary">{description ?? t('ui.error.description')}</p>
      {onRetry && <Button variant="secondary" className="mt-5" leadingIcon={<RefreshIcon />} onClick={onRetry}>{t('ui.retry')}</Button>}
    </div>
  );
}
