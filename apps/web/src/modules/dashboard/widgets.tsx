import type { ReactNode } from 'react';
import type { ModuleId } from '@wingora/permissions';
import type { MessageKey } from '@wingora/localization';
import type { ListWidget, MetricWidget, UnavailableWidget, WidgetKey } from '@/api/dashboard';
import { Avatar, Card, EmptyState, InboxIcon, Skeleton } from '@/components/ui';
import { useI18n } from '@/i18n/i18n';
import { moduleLabel } from '@/shell/module-meta';
import { cn } from '@/lib/cn';

export const widgetTitle = (key: WidgetKey): MessageKey => `dashboard.widget.${key}`;

export function useModuleName() {
  const { t } = useI18n();
  return (module: string) => (module === 'contracts' ? t('dashboard.source.contracts') : t(moduleLabel(module as ModuleId)));
}

function WidgetCard({ title, children, className, description }: { title: ReactNode; description?: ReactNode; children: ReactNode; className?: string }) {
  return <Card headingLevel={2} title={title} description={description} className={cn('min-w-0', className)}>{children}</Card>;
}

export function MetricCard({ widgetKey, widget, hint }: { widgetKey: WidgetKey; widget: MetricWidget; hint?: ReactNode }) {
  const { t, locale } = useI18n();
  return (
    <WidgetCard title={t(widgetTitle(widgetKey))}>
      <p className="text-3xl font-semibold tracking-tight text-fg tabular-nums">{new Intl.NumberFormat(locale).format(widget.value)}</p>
      {hint && <p className="mt-1 text-sm text-fg-muted">{hint}</p>}
    </WidgetCard>
  );
}

export function UnavailableCard({ widgetKey, widget, className }: { widgetKey: WidgetKey; widget: UnavailableWidget; className?: string }) {
  const { t } = useI18n();
  const moduleName = useModuleName();
  return (
    <WidgetCard title={t(widgetTitle(widgetKey))} className={className}>
      <p className="text-3xl font-semibold text-fg-disabled" aria-hidden="true">—</p>
      <p className="mt-1 text-sm text-fg-muted">{t('dashboard.unavailable', { module: moduleName(widget.module) })}</p>
    </WidgetCard>
  );
}

export function UpcomingCard({ widgetKey, widget, anniversaries = false }: { widgetKey: WidgetKey; widget: ListWidget; anniversaries?: boolean }) {
  const { t, locale } = useI18n();
  const date = new Intl.DateTimeFormat(locale, { month: 'short', day: 'numeric', timeZone: 'UTC' });
  const when = (days: number) => (days === 0 ? t('dashboard.today') : days === 1 ? t('dashboard.tomorrow') : t('dashboard.inDays', { count: days }));
  return (
    <WidgetCard title={t(widgetTitle(widgetKey))}>
      {widget.items.length === 0 ? (
        <EmptyState icon={<InboxIcon size={20} />} title={t('dashboard.upcomingEmpty')} className="py-6" />
      ) : (
        <ul className="-my-2 divide-y divide-line">
          {widget.items.map((item) => (
            <li key={item.employee_id} className="flex min-w-0 items-center gap-3 py-2.5">
              <Avatar name={item.name} size="sm" decorative />
              <div className="min-w-0 flex-1">
                <p className="truncate text-sm font-medium text-fg">{item.name}</p>
                <p className="text-xs text-fg-muted">
                  {date.format(new Date(`${item.date}T00:00:00Z`))}
                  {anniversaries && item.years !== undefined && <> · {item.years === 1 ? t('dashboard.oneYear') : t('dashboard.years', { count: item.years })}</>}
                </p>
              </div>
              <span className="shrink-0 text-xs font-medium text-fg-secondary">{when(item.in_days)}</span>
            </li>
          ))}
        </ul>
      )}
    </WidgetCard>
  );
}

export function DashboardSkeleton() {
  const { t } = useI18n();
  return (
    <div role="status" aria-live="polite" className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
      <span className="sr-only">{t('dashboard.loading')}</span>
      {Array.from({ length: 6 }, (_, i) => (
        <div key={`m${i}`} className="rounded-lg border border-line bg-surface p-5">
          <Skeleton className="h-3 w-28" />
          <Skeleton className="mt-4 h-8 w-20" />
          <Skeleton className="mt-3 h-3 w-36" />
        </div>
      ))}
      <div className="rounded-lg border border-line bg-surface p-5 sm:col-span-2">
        <Skeleton className="h-3 w-32" />
        <Skeleton className="mt-5 h-40 w-full" />
      </div>
      <div className="rounded-lg border border-line bg-surface p-5">
        <Skeleton className="h-3 w-32" />
        {Array.from({ length: 4 }, (_, i) => <Skeleton key={i} className="mt-4 h-6 w-full" />)}
      </div>
    </div>
  );
}
