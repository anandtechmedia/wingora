import { useEffect, useState } from 'react';
import type { MessageKey } from '@wingora/localization';
import { ApiNotConnectedError } from '@/api/dashboard';
import { ApiError } from '@/api/http';
import type { EmployeeStatus, EmployeeSummary } from '@/api/employees';
import { Badge, EmptyState, ErrorState, InboxIcon, LoadingState } from '@/components/ui';
import { useI18n } from '@/i18n/i18n';

export interface Resource<T> { data?: T; error?: unknown; loading: boolean; reload: () => void }

/** Runs `load` (memoize it with useCallback) whenever it changes or on reload; cancels the previous request and keeps stale data while loading. */
export function useResource<T>(load: (signal: AbortSignal) => Promise<T>): Resource<T> {
  const [attempt, setAttempt] = useState(0);
  const [settled, setSettled] = useState<{ load?: unknown; attempt: number; data?: T; error?: unknown }>({ attempt: -1 });
  useEffect(() => {
    const controller = new AbortController();
    load(controller.signal).then(
      (data) => setSettled({ load, attempt, data }),
      (error: unknown) => { if (!controller.signal.aborted) setSettled((s) => ({ load, attempt, data: s.data, error })); },
    );
    return () => controller.abort();
  }, [load, attempt]);
  const loading = settled.load !== load || settled.attempt !== attempt;
  return { data: settled.data, error: loading ? undefined : settled.error, loading, reload: () => setAttempt((a) => a + 1) };
}

/** Field-level messages from a Laravel 422 response. */
export function validationErrors(error: unknown): Record<string, string> {
  if (!(error instanceof ApiError) || error.status !== 422) return {};
  const errors = (error.body as { errors?: Record<string, string[]> } | null)?.errors ?? {};
  return Object.fromEntries(Object.entries(errors).map(([k, v]) => [k, v[0] ?? '']));
}

export function errorMessage(error: unknown, fallback: string): string {
  const body = error instanceof ApiError ? (error.body as { message?: string; detail?: string } | null) : null;
  return body?.detail ?? body?.message ?? fallback;
}

const tones: Record<EmployeeStatus, 'success' | 'info' | 'warning' | 'neutral'> = { active: 'success', on_leave: 'info', suspended: 'warning', terminated: 'neutral' };
export function StatusBadge({ status }: { status: EmployeeStatus }) {
  const { t } = useI18n();
  return <Badge tone={tones[status]} dot size="sm">{t(`employees.status.${status}` as MessageKey)}</Badge>;
}

export const displayName = (e: Pick<EmployeeSummary, 'first_name' | 'last_name' | 'preferred_name'>) =>
  `${e.preferred_name || e.first_name} ${e.last_name}`;

/** Loading / not-connected / error / empty handling shared by every tab. */
export function ResourceView<T>({ resource, empty, isEmpty, children }: {
  resource: Resource<T>; empty: string; isEmpty?: (data: T) => boolean; children: (data: T) => React.ReactNode;
}) {
  const { t } = useI18n();
  if (resource.loading && resource.data === undefined) return <LoadingState variant="skeleton" rows={3} />;
  if (resource.error instanceof ApiNotConnectedError) return <EmptyState title={t('employees.notConnectedTitle')} description={t('employees.notConnectedDescription')} />;
  if (resource.error) return <ErrorState description={errorMessage(resource.error, t('employees.loadError'))} onRetry={resource.reload} />;
  if (resource.data === undefined) return null;
  if (isEmpty?.(resource.data)) return <EmptyState icon={<InboxIcon size={20} />} title={empty} />;
  return <>{children(resource.data)}</>;
}

export function DefinitionList({ items }: { items: [string, React.ReactNode][] }) {
  const { t } = useI18n();
  return (
    <dl className="grid gap-x-6 gap-y-4 sm:grid-cols-2 lg:grid-cols-3">
      {items.map(([label, value]) => (
        <div key={label} className="min-w-0">
          <dt className="text-xs font-medium text-fg-muted">{label}</dt>
          <dd className="mt-1 break-words text-sm text-fg">{value === null || value === undefined || value === '' ? <span className="text-fg-muted">{t('employees.none')}</span> : value}</dd>
        </div>
      ))}
    </dl>
  );
}
