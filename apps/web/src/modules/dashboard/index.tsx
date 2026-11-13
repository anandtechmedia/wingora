import { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router';
import { navigationFor } from '@wingora/permissions';
import { ApiNotConnectedError, useDashboardSource, type DashboardData, type DashboardQuery, type LegalEntitySummary } from '@/api/dashboard';
import { Badge, Button, Card, ChevronRightIcon, DatePicker, EmptyState, ErrorState, InboxIcon, Select } from '@/components/ui';
import { useSession } from '@/auth/session';
import { useI18n } from '@/i18n/i18n';
import { moduleIcons, moduleLabel } from '@/shell/module-meta';
import { PageHeader } from '@/shell/page-header';
import { useRegionName, useWorkspace } from '@/shell/workspace';
import { TrendChart } from './trend-chart';
import { DashboardSkeleton, MetricCard, UnavailableCard, UpcomingCard, widgetTitle } from './widgets';

const COUNTRIES = ['US', 'AE', 'CA', 'MX'] as const;
const EMPTY_QUERY: DashboardQuery = { country: '', legalEntity: '', from: '', to: '' };
type Result = { key: string; data?: DashboardData; error?: unknown };

/** Figures come only from `GET /api/v1/dashboard`; the server decides which widgets the user receives. */
export default function Dashboard() {
  const { t, locale } = useI18n();
  const { me } = useSession();
  const { organization, entity } = useWorkspace();
  const regionName = useRegionName();
  const source = useDashboardSource();

  const [query, setQuery] = useState<DashboardQuery>(EMPTY_QUERY);
  const [attempt, setAttempt] = useState(0);
  const [resets, setResets] = useState(0);
  const [result, setResult] = useState<Result | null>(null);
  const [entities, setEntities] = useState<LegalEntitySummary[]>([]);
  const key = JSON.stringify([query, attempt]);

  useEffect(() => {
    const controller = new AbortController();
    source.load(query, controller.signal).then(
      (data) => setResult({ key, data }),
      (error: unknown) => { if (!controller.signal.aborted) setResult({ key, error }); },
    );
    return () => controller.abort();
  }, [source, query, key]);

  useEffect(() => {
    const controller = new AbortController();
    source.legalEntities(controller.signal).then(setEntities, () => { if (!controller.signal.aborted) setEntities([]); });
    return () => controller.abort();
  }, [source]);

  const current = result?.key === key ? result : null;
  const shown = current?.data ? current : result?.data ? result : null;
  const loading = current === null;
  const update = (patch: Partial<DashboardQuery>) => setQuery((q) => ({ ...q, ...patch }));
  const entityOptions = entities.filter((e) => !query.country || e.country_code === query.country);
  const dateFormat = useMemo(() => new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeZone: 'UTC' }), [locale]);
  const fmtDate = (iso: string) => dateFormat.format(new Date(`${iso}T00:00:00Z`));

  let body;
  if (current?.error instanceof ApiNotConnectedError) {
    body = <Card><EmptyState icon={<InboxIcon size={22} />} title={t('dashboard.notConnectedTitle')} description={t('dashboard.notConnectedDescription')} headingLevel={2} /></Card>;
  } else if (current?.error) {
    body = <Card><ErrorState title={t('dashboard.errorTitle')} description={t('dashboard.errorDescription')} headingLevel={2} onRetry={() => setAttempt((n) => n + 1)} /></Card>;
  } else if (!shown?.data) {
    body = <DashboardSkeleton />;
  } else {
    body = <Widgets data={shown.data} fmtDate={fmtDate} />;
  }

  return (
    <>
      <PageHeader title={t('dashboard.greeting', { name: me?.user.name.split(' ')[0] ?? '' })}
        description={t('dashboard.context', { organization: organization.name, entity: entity?.name ?? t('shell.allEntities') })}
        actions={shown?.data && shown.data.scope !== 'none' ? (
          <Badge tone={shown.data.scope === 'organization' ? 'accent' : 'neutral'}>
            {t(shown.data.scope === 'organization' ? 'dashboard.scopeOrganization' : 'dashboard.scopeDepartments')}
          </Badge>
        ) : undefined} />

      <section aria-label={t('dashboard.filters')} className="mb-6 grid gap-3 rounded-lg border border-line bg-surface p-4 sm:grid-cols-2 lg:grid-cols-[repeat(4,minmax(0,1fr))_auto] lg:items-end">
        <Select label={t('dashboard.country')} value={query.country} onChange={(e) => update({ country: e.target.value, legalEntity: '' })}
          placeholder={t('dashboard.allCountries')} options={COUNTRIES.map((c) => ({ value: c, label: regionName(locale, c) }))} />
        <Select label={t('dashboard.legalEntity')} value={query.legalEntity} onChange={(e) => update({ legalEntity: e.target.value })} disabled={entityOptions.length === 0}
          placeholder={t('dashboard.allEntities')} options={entityOptions.map((e) => ({ value: e.id, label: e.name }))} />
        <DatePicker key={`from-${resets}`} label={t('dashboard.from')} value={query.from || shown?.data?.filters.from || ''} max={query.to || undefined} onChange={(from) => update({ from })} />
        <DatePicker key={`to-${resets}`} label={t('dashboard.to')} value={query.to || shown?.data?.filters.to || ''} min={query.from || undefined} onChange={(to) => update({ to })} />
        <Button variant="tertiary" onClick={() => { setQuery(EMPTY_QUERY); setResets((n) => n + 1); }}>{t('dashboard.resetFilters')}</Button>
      </section>

      <div aria-busy={loading} className="relative">
        {loading && shown?.data && <p role="status" className="sr-only">{t('dashboard.refreshing')}</p>}
        <div className={loading && shown?.data ? 'opacity-60 transition-opacity' : undefined}>{body}</div>
      </div>

      <ModulesCard />
    </>
  );
}

function Widgets({ data, fmtDate }: { data: DashboardData; fmtDate: (iso: string) => string }) {
  const { t, locale } = useI18n();
  const w = data.widgets;
  if (Object.keys(w).length === 0) {
    return <Card><EmptyState icon={<InboxIcon size={22} />} title={t('dashboard.noWidgetsTitle')} description={t('dashboard.noWidgetsDescription')} headingLevel={2} /></Card>;
  }
  const percent = new Intl.NumberFormat(locale, { style: 'percent', maximumFractionDigits: 0 });
  const activeShare = w.total_employees && w.active_employees && w.total_employees.value > 0
    ? t('dashboard.activeShare', { percent: percent.format(w.active_employees.value / w.total_employees.value) }) : undefined;

  return (
    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
      {w.total_employees && <MetricCard widgetKey="total_employees" widget={w.total_employees} />}
      {w.active_employees && <MetricCard widgetKey="active_employees" widget={w.active_employees} hint={activeShare} />}
      {w.new_joiners && <MetricCard widgetKey="new_joiners" widget={w.new_joiners} hint={t('dashboard.newJoinersHint', { from: fmtDate(data.filters.from), to: fmtDate(data.filters.to) })} />}
      {w.pending_leave_approvals && <UnavailableCard widgetKey="pending_leave_approvals" widget={w.pending_leave_approvals} />}
      {w.todays_attendance && <UnavailableCard widgetKey="todays_attendance" widget={w.todays_attendance} />}
      {w.absentees && <UnavailableCard widgetKey="absentees" widget={w.absentees} />}

      {w.workforce_trends && (
        <Card headingLevel={2} title={t(widgetTitle('workforce_trends'))} description={t('dashboard.trendsDescription')} className="min-w-0 sm:col-span-2">
          {w.workforce_trends.series.every((p) => p.headcount === 0 && p.hires === 0 && p.exits === 0)
            ? <EmptyState icon={<InboxIcon size={20} />} title={t('dashboard.trendsEmpty')} className="py-8" />
            : <TrendChart series={w.workforce_trends.series} />}
        </Card>
      )}
      {w.recruitment_pipeline && <UnavailableCard widgetKey="recruitment_pipeline" widget={w.recruitment_pipeline} />}

      {w.upcoming_birthdays && <UpcomingCard widgetKey="upcoming_birthdays" widget={w.upcoming_birthdays} />}
      {w.upcoming_anniversaries && <UpcomingCard widgetKey="upcoming_anniversaries" widget={w.upcoming_anniversaries} anniversaries />}
      {w.pending_onboarding_tasks && <UnavailableCard widgetKey="pending_onboarding_tasks" widget={w.pending_onboarding_tasks} />}
      {w.contract_expirations && <UnavailableCard widgetKey="contract_expirations" widget={w.contract_expirations} />}
    </div>
  );
}

function ModulesCard() {
  const { t } = useI18n();
  const { permissions } = useSession();
  const modules = navigationFor(permissions).flatMap((g) => g.modules).filter((m) => m.id !== 'dashboard');
  if (modules.length === 0) return null;
  return (
    <Card headingLevel={2} title={t('dashboard.modules')} description={t('dashboard.modulesDescription')} className="mt-6">
      <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        {modules.map((m) => {
          const Icon = moduleIcons[m.id];
          return (
            <li key={m.id}>
              <Link to={`/${m.path}`} className="group flex items-center gap-3 rounded-lg border border-line bg-surface p-3 text-sm font-medium text-fg hover:border-line-strong hover:bg-surface-muted">
                <span className="inline-flex size-9 items-center justify-center rounded-md bg-accent-subtle text-accent-fg"><Icon size={18} /></span>
                <span className="flex-1">{t(moduleLabel(m.id))}</span>
                <ChevronRightIcon className="text-fg-muted rtl:rotate-180" />
              </Link>
            </li>
          );
        })}
      </ul>
    </Card>
  );
}
