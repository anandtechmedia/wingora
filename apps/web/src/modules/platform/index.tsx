import { useCallback, useState } from 'react';
import { Permission as P } from '@wingora/permissions';
import type { MessageKey } from '@wingora/localization';
import { ApiNotConnectedError } from '@/api/dashboard';
import type { Page } from '@/api/employees';
import {
  usePlatformSource, type AuditEntry, type AuditQuery, type CheckStatus, type HealthReport, type SupportOverview, type SupportSection,
  type TenantDetail, type TenantRow, type UsageRow, type UsageSort,
} from '@/api/platform';
import { Badge, Button, Card, DataTable, Drawer, EmptyState, Input, Modal, Select, Tabs, useToast, type DataTableColumn } from '@/components/ui';
import { PageHeader } from '@/shell/page-header';
import { useI18n } from '@/i18n/i18n';
import { errorMessage, FormError, formError, ResourceView, useResource, validationErrors } from '@/modules/employees/shared';
import { Pager } from '@/modules/attendance';
import { useCan, useSave } from '@/modules/recruitment/shared';

type Tone = 'neutral' | 'warning' | 'success' | 'danger' | 'accent';
const COUNTRIES = ['US', 'AE', 'CA', 'MX'];
const SUBSCRIPTION_STATUSES = ['trialing', 'active', 'past_due', 'unpaid', 'canceled', 'incomplete'];
const tenantTone: Record<string, Tone> = { active: 'success', suspended: 'danger', deleted: 'neutral' };
const subscriptionTone: Record<string, Tone> = { trialing: 'accent', active: 'success', past_due: 'warning', unpaid: 'danger', canceled: 'neutral', incomplete: 'warning' };
const checkTone: Record<CheckStatus, Tone> = { ok: 'success', degraded: 'warning', down: 'danger' };

export function usePlatformT() {
  const { t, locale } = useI18n();
  const pt = (key: string, params?: Record<string, string | number>) => t(`platform.${key}` as MessageKey, params);
  const date = (iso: string) => new Intl.DateTimeFormat(locale, { dateStyle: 'medium' }).format(new Date(iso));
  const when = (iso: string) => new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(iso));
  const money = (minor: number, currency: string) => new Intl.NumberFormat(locale, { style: 'currency', currency }).format(minor / 100);
  const count = (n: number) => new Intl.NumberFormat(locale).format(n);
  const bytes = (n: number) => {
    const units = ['byte', 'kilobyte', 'megabyte', 'gigabyte', 'terabyte'];
    const i = n > 0 ? Math.min(units.length - 1, Math.floor(Math.log(n) / Math.log(1024))) : 0;
    return new Intl.NumberFormat(locale, { style: 'unit', unit: units[i], maximumFractionDigits: 1 }).format(n / 1024 ** i);
  };
  const of = (used: number, limit: number | null) => (limit === null ? pt('usedUnlimited', { used: count(used) }) : pt('usedOf', { used: count(used), limit: count(limit) }));
  return { pt, t, date, when, money, count, bytes, of };
}

function NotConnected() {
  const { pt } = usePlatformT();
  return <EmptyState headingLevel={2} title={pt('notConnectedTitle')} description={pt('notConnectedDescription')} />;
}

export default function Platform() {
  const { pt, t } = usePlatformT();
  const can = useCan();
  const tabs = [
    can(P.PlatformTenantsView) && { id: 'tenants', label: pt('tenantsTab'), content: <Tenants /> },
    can(P.PlatformBillingView) && { id: 'subscriptions', label: pt('subscriptionsTab'), content: <Subscriptions /> },
    can(P.PlatformBillingView) && { id: 'usage', label: pt('usageTab'), content: <UsageTab /> },
    can(P.PlatformAuditView) && { id: 'audit', label: pt('auditTab'), content: <Audit /> },
    can(P.PlatformHealthView) && { id: 'health', label: pt('healthTab'), content: <Health /> },
  ].filter((x): x is { id: string; label: string; content: React.ReactElement } => Boolean(x));
  return (
    <>
      <PageHeader title={t('nav.module.platform')} description={pt('subtitle')} />
      {tabs.length > 0 ? <Tabs label={t('nav.module.platform')} tabs={tabs} /> : <EmptyState headingLevel={2} title={pt('nothingToShow')} />}
    </>
  );
}

function SubscriptionBadge({ status }: { status: string }) {
  const { pt } = usePlatformT();
  return <Badge tone={subscriptionTone[status] ?? 'neutral'} dot size="sm">{pt(`subscriptionStatus.${status}`)}</Badge>;
}

function Tenants() {
  const { pt, date } = usePlatformT();
  const source = usePlatformSource();
  const can = useCan();
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [subscriptionStatus, setSubscriptionStatus] = useState('');
  const [page, setPage] = useState(1);
  const [open, setOpen] = useState<string | null>(null);
  const [creating, setCreating] = useState(false);
  const tenants = useResource(useCallback((signal: AbortSignal) => source.tenants({ search, status, plan: '', subscription_status: subscriptionStatus }, page, signal),
    [source, search, status, subscriptionStatus, page]));
  if (tenants.error instanceof ApiNotConnectedError) return <NotConnected />;
  const columns: DataTableColumn<TenantRow>[] = [
    { id: 'name', header: pt('organization'), primary: true, cell: (r) => (
      <button type="button" className="text-start font-medium text-fg-link hover:underline" onClick={() => setOpen(r.id)}>{r.name}<span className="block text-xs font-normal text-fg-muted">{r.slug}</span></button>
    ) },
    { id: 'country', header: pt('country'), hideBelow: 'md', cell: (r) => r.country_code },
    { id: 'status', header: pt('statusLabel'), cell: (r) => <Badge tone={tenantTone[r.status] ?? 'neutral'} dot size="sm">{pt(`tenantStatus.${r.status}`)}</Badge> },
    { id: 'plan', header: pt('plan'), cell: (r) => (r.subscription ? <span className="flex flex-wrap items-center gap-2">{r.subscription.plan ?? '—'}<SubscriptionBadge status={r.subscription.status} /></span> : '—') },
    { id: 'access', header: pt('access'), hideBelow: 'lg', cell: (r) => (r.subscription ? pt(`accessMode.${r.subscription.access}`) : '—') },
    { id: 'created', header: pt('created'), hideBelow: 'lg', cell: (r) => (r.created_at ? date(r.created_at) : '—') },
  ];
  const filter = (fn: (v: string) => void) => (v: string) => { fn(v); setPage(1); };
  return (
    <Card title={pt('tenantsTab')} headingLevel={2} padding="none" description={pt('tenantsHint')}
      actions={can(P.PlatformTenantsManage) ? <Button size="sm" onClick={() => setCreating(true)}>{pt('newTenant')}</Button> : undefined}>
      <div className="grid gap-3 border-b border-line p-4 md:grid-cols-3">
        <Input label={pt('search')} type="search" value={search} maxLength={100} onChange={(e) => filter(setSearch)(e.target.value)} />
        <Select label={pt('statusLabel')} value={status} placeholder={pt('allStatuses')} onChange={(e) => filter(setStatus)(e.target.value)}
          options={['active', 'suspended', 'deleted'].map((s) => ({ value: s, label: pt(`tenantStatus.${s}`) }))} />
        <Select label={pt('subscriptionLabel')} value={subscriptionStatus} placeholder={pt('allStatuses')} onChange={(e) => filter(setSubscriptionStatus)(e.target.value)}
          options={SUBSCRIPTION_STATUSES.map((s) => ({ value: s, label: pt(`subscriptionStatus.${s}`) }))} />
      </div>
      <DataTable caption={pt('tenantsTab')} hideCaption columns={columns} rows={tenants.data?.data ?? []} getRowId={(r) => r.id}
        loading={tenants.loading && tenants.data === undefined} error={tenants.error ? errorMessage(tenants.error, pt('loadError')) : null} onRetry={tenants.reload}
        empty={<EmptyState title={pt('noTenants')} />} />
      <Pager meta={tenants.data?.meta} onPage={setPage} />
      {open && <TenantDrawer id={open} onClose={() => setOpen(null)} onChanged={tenants.reload} />}
      {creating && <NewTenantDialog onClose={() => setCreating(false)} onDone={() => { setCreating(false); tenants.reload(); }} />}
    </Card>
  );
}

function Fact({ label, children }: { label: string; children: React.ReactNode }) {
  return <div className="min-w-0"><dt className="text-xs text-fg-muted">{label}</dt><dd className="text-sm text-fg">{children}</dd></div>;
}

function TenantDrawer({ id, onClose, onChanged }: { id: string; onClose: () => void; onChanged: () => void }) {
  const { pt, date, when, bytes, of } = usePlatformT();
  const source = usePlatformSource();
  const can = useCan();
  const tenant = useResource(useCallback((signal: AbortSignal) => source.tenant(id, signal), [source, id]));
  const [dialog, setDialog] = useState<'status' | 'trial' | 'plan' | null>(null);
  const done = () => { setDialog(null); tenant.reload(); onChanged(); };
  const d = tenant.data;
  return (
    <Drawer open onClose={onClose} size="lg" title={d?.name ?? pt('organization')} description={d ? `${d.slug} · ${d.country_code}` : undefined}>
      <ResourceView resource={tenant} empty={pt('nothingToShow')} errorLabel={pt('loadError')}>
        {(t: TenantDetail) => {
          const s = t.subscription_detail;
          return (
            <div className="grid gap-6">
              <dl className="grid gap-4 sm:grid-cols-2">
                <Fact label={pt('statusLabel')}><Badge tone={tenantTone[t.status] ?? 'neutral'} dot size="sm">{pt(`tenantStatus.${t.status}`)}</Badge></Fact>
                <Fact label={pt('subscriptionLabel')}>{s ? <span className="flex flex-wrap items-center gap-2">{s.plan.name}<SubscriptionBadge status={s.status} /></span> : '—'}</Fact>
                <Fact label={pt('access')}>{s ? (s.access.reason ? `${pt(`accessMode.${s.access.mode}`)} · ${pt(`accessReason.${s.access.reason}`)}` : pt(`accessMode.${s.access.mode}`)) : '—'}</Fact>
                <Fact label={pt('providerLabel')}>{s ? pt(`provider.${s.provider}`) : '—'}</Fact>
                {s?.trial_ends_at && <Fact label={pt('trialEnds')}>{date(s.trial_ends_at)}</Fact>}
                {s?.current_period_end && <Fact label={s.cancel_at_period_end ? pt('endsOn') : pt('renewsOn')}>{date(s.current_period_end)}</Fact>}
                <Fact label={pt('users')}>{t.users}</Fact>
                {t.usage && <Fact label={pt('employees')}>{of(t.usage.employees.used, t.usage.employees.limit)}</Fact>}
                {t.usage && <Fact label={pt('aiRequests')}>{of(t.usage.ai_requests.used, t.usage.ai_requests.limit)}</Fact>}
                {t.usage && <Fact label={pt('storage')}>{bytes(t.usage.storage_bytes)}</Fact>}
                <Fact label={pt('supportAccess')}>{t.support_access ? pt('supportUntil', { date: when(t.support_access.expires_at) }) : pt('supportNone')}</Fact>
                <Fact label={pt('legalHold')}>{t.legal_hold ? pt('yes') : pt('no')}</Fact>
                {t.deletion_scheduled_for && <Fact label={pt('deletionScheduled')}>{date(t.deletion_scheduled_for)}</Fact>}
              </dl>
              {t.status !== 'deleted' && (can(P.PlatformTenantsManage) || (s && can(P.PlatformBillingManage))) && (
                <div className="flex flex-wrap gap-2">
                  {can(P.PlatformTenantsManage) && (
                    <Button variant={t.status === 'active' ? 'danger' : 'primary'} size="sm" onClick={() => setDialog('status')}>{t.status === 'active' ? pt('suspend') : pt('reactivate')}</Button>
                  )}
                  {s && can(P.PlatformBillingManage) && <Button variant="secondary" size="sm" onClick={() => setDialog('trial')}>{pt('extendTrial')}</Button>}
                  {s && can(P.PlatformBillingManage) && <Button variant="secondary" size="sm" onClick={() => setDialog('plan')}>{pt('assignPlan')}</Button>}
                </div>
              )}
              {can(P.PlatformSupportAccess) && t.status !== 'deleted' && <SupportPanel id={t.id} />}
              {dialog === 'status' && <StatusDialog tenant={t} onClose={() => setDialog(null)} onDone={done} />}
              {(dialog === 'trial' || dialog === 'plan') && s && <SubscriptionDialog tenant={t} version={s.version} mode={dialog} onClose={() => setDialog(null)} onDone={done} />}
            </div>
          );
        }}
      </ResourceView>
    </Drawer>
  );
}

function StatusDialog({ tenant, onClose, onDone }: { tenant: TenantDetail; onClose: () => void; onDone: () => void }) {
  const { pt, t } = usePlatformT();
  const source = usePlatformSource();
  const { toast } = useToast();
  const suspend = tenant.status === 'active';
  const [reason, setReason] = useState('');
  const { error, busy, save } = useSave();
  const fields = validationErrors(error);
  const submit = () => save(async () => {
    await source.setStatus(tenant.id, suspend ? 'suspended' : 'active', reason.trim());
    toast({ title: suspend ? pt('suspended') : pt('reactivated'), tone: 'success' });
    onDone();
  });
  return (
    <Modal open onClose={onClose} role={suspend ? 'alertdialog' : 'dialog'} title={suspend ? pt('suspendTitle', { name: tenant.name }) : pt('reactivateTitle', { name: tenant.name })}
      description={suspend ? pt('suspendHint') : pt('reactivateHint')}
      footer={<><Button variant="secondary" onClick={onClose}>{t('ui.cancel')}</Button>
        <Button variant={suspend ? 'danger' : 'primary'} loading={busy} disabled={reason.trim() === ''} onClick={submit}>{suspend ? pt('suspend') : pt('reactivate')}</Button></>}>
      <div className="grid gap-4">
        <Input label={pt('reason')} required maxLength={500} value={reason} error={fields.reason} hint={pt('reasonHint')} onChange={(e) => setReason(e.target.value)} />
        <FormError message={error ? formError(error, pt('saveFailed')) : null} />
      </div>
    </Modal>
  );
}

function SubscriptionDialog({ tenant, version, mode, onClose, onDone }: { tenant: TenantDetail; version: number; mode: 'trial' | 'plan'; onClose: () => void; onDone: () => void }) {
  const { pt, t } = usePlatformT();
  const source = usePlatformSource();
  const { toast } = useToast();
  const plans = useResource(useCallback((signal: AbortSignal) => (mode === 'plan' ? source.plans(signal) : Promise.resolve([])), [source, mode]));
  const [days, setDays] = useState('14');
  const [plan, setPlan] = useState('');
  const [endsAt, setEndsAt] = useState('');
  const [reason, setReason] = useState('');
  const { error, busy, save } = useSave();
  const fields = validationErrors(error);
  const ready = reason.trim() !== '' && (mode === 'trial' ? Number(days) >= 1 && Number(days) <= 90 : plan !== '');
  const submit = () => save(async () => {
    await source.changeSubscription(tenant.id, mode === 'trial'
      ? { action: 'extend_trial', days: Number(days), reason: reason.trim(), version }
      : { action: 'assign_plan', plan, ends_at: endsAt || null, reason: reason.trim(), version });
    toast({ title: mode === 'trial' ? pt('trialExtended') : pt('planAssigned'), tone: 'success' });
    onDone();
  });
  return (
    <Modal open onClose={onClose} title={mode === 'trial' ? pt('extendTrialTitle', { name: tenant.name }) : pt('assignPlanTitle', { name: tenant.name })}
      description={mode === 'trial' ? pt('extendTrialHint') : pt('assignPlanHint')}
      footer={<><Button variant="secondary" onClick={onClose}>{t('ui.cancel')}</Button><Button loading={busy} disabled={!ready} onClick={submit}>{pt('save')}</Button></>}>
      <div className="grid gap-4">
        {mode === 'trial'
          ? <Input label={pt('days')} type="number" min={1} max={90} required value={days} error={fields.days} hint={pt('daysHint')} onChange={(e) => setDays(e.target.value)} />
          : <>
            <Select label={pt('plan')} required value={plan} placeholder={pt('choosePlan')} error={fields.plan} onChange={(e) => setPlan(e.target.value)}
              options={(plans.data ?? []).filter((p) => p.active).map((p) => ({ value: p.code, label: p.name }))} />
            <Input label={pt('endsAt')} type="date" value={endsAt} error={fields.ends_at} hint={pt('endsAtHint')} onChange={(e) => setEndsAt(e.target.value)} />
          </>}
        <Input label={pt('reason')} required maxLength={500} value={reason} error={fields.reason} hint={pt('reasonHint')} onChange={(e) => setReason(e.target.value)} />
        <FormError message={error ? (fields.version ? pt('conflict') : formError(error, pt('saveFailed'))) : null} />
      </div>
    </Modal>
  );
}

function NewTenantDialog({ onClose, onDone }: { onClose: () => void; onDone: () => void }) {
  const { pt, t } = usePlatformT();
  const source = usePlatformSource();
  const { toast } = useToast();
  const [form, setForm] = useState({ name: '', slug: '', country_code: 'US', owner_name: '', owner_email: '' });
  const set = (k: keyof typeof form) => (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) => setForm((f) => ({ ...f, [k]: e.target.value }));
  const { error, busy, save } = useSave();
  const fields = validationErrors(error);
  const ready = form.name.trim() !== '' && form.slug.trim() !== '' && form.owner_name.trim() !== '' && form.owner_email.trim() !== '';
  const submit = () => save(async () => {
    await source.createTenant({ ...form, name: form.name.trim(), slug: form.slug.trim().toLowerCase(), owner_name: form.owner_name.trim(), owner_email: form.owner_email.trim() });
    toast({ title: pt('tenantCreated'), tone: 'success' });
    onDone();
  });
  return (
    <Modal open onClose={onClose} title={pt('newTenant')} description={pt('newTenantHint')}
      footer={<><Button variant="secondary" onClick={onClose}>{t('ui.cancel')}</Button><Button loading={busy} disabled={!ready} onClick={submit}>{pt('create')}</Button></>}>
      <div className="grid gap-4">
        <Input label={pt('organizationName')} required maxLength={160} value={form.name} error={fields.name} onChange={set('name')} />
        <Input label={pt('slug')} required maxLength={63} value={form.slug} error={fields.slug} hint={pt('slugHint')} onChange={set('slug')} />
        <Select label={pt('country')} value={form.country_code} error={fields.country_code} onChange={set('country_code')} options={COUNTRIES.map((c) => ({ value: c, label: c }))} />
        <Input label={pt('ownerName')} required maxLength={160} value={form.owner_name} error={fields.owner_name} onChange={set('owner_name')} />
        <Input label={pt('ownerEmail')} type="email" required maxLength={255} value={form.owner_email} error={fields.owner_email} hint={pt('ownerEmailHint')} onChange={set('owner_email')} />
        <FormError message={error ? formError(error, pt('saveFailed')) : null} />
      </div>
    </Modal>
  );
}

const sectionColumns: Record<Exclude<SupportSection, 'overview'>, string[]> = {
  users: ['name', 'email', 'status', 'roles', 'mfa_enabled', 'last_login_at'],
  employees: ['employee_number', 'name', 'job_title', 'department', 'status'],
  audit: ['created_at', 'event', 'category', 'outcome'],
  payroll: ['created_at', 'type', 'status', 'currency', 'employee_count'],
};

function SupportPanel({ id }: { id: string }) {
  const { pt, when } = usePlatformT();
  const source = usePlatformSource();
  const status = useResource(useCallback((signal: AbortSignal) => source.support(id, signal), [source, id]));
  const [section, setSection] = useState<SupportSection>('overview');
  const s = status.data;
  return (
    <section aria-labelledby="support-heading" className="grid gap-3 border-t border-line pt-4">
      <h3 id="support-heading" className="text-sm font-semibold text-fg">{pt('supportAccess')}</h3>
      {status.loading && !s && <p className="text-sm text-fg-muted">{pt('loading')}</p>}
      {status.error != null && <p role="alert" className="text-sm text-danger-fg">{errorMessage(status.error, pt('loadError'))}</p>}
      {s && !s.active && <p className="text-sm text-fg-muted">{pt('supportNeedsGrant')}</p>}
      {s?.active && (
        <>
          <p className="text-sm text-fg-muted">{pt('supportActive', { date: s.expires_at ? when(s.expires_at) : '—' })} {s.include_payroll ? pt('supportPayrollIncluded') : pt('supportPayrollExcluded')}</p>
          {s.reason && <p className="text-sm text-fg"><span className="text-fg-muted">{pt('ownerReason')}: </span>{s.reason}</p>}
          <p className="text-xs text-fg-muted">{pt('supportAudited')}</p>
          <Select label={pt('section')} value={section} onChange={(e) => setSection(e.target.value as SupportSection)}
            options={s.sections.map((x) => ({ value: x, label: pt(`supportSection.${x}`) }))} />
          {s.sections.includes(section) && <SupportSectionView key={section} id={id} section={section} />}
        </>
      )}
    </section>
  );
}

function SupportSectionView({ id, section }: { id: string; section: SupportSection }) {
  const { pt, when, bytes, of } = usePlatformT();
  const source = usePlatformSource();
  const [page, setPage] = useState(1);
  const view = useResource(useCallback((signal: AbortSignal) => source.supportSection(id, section, page, signal), [source, id, section, page]));
  if (section === 'overview') {
    return (
      <ResourceView resource={view} empty={pt('nothingToShow')} errorLabel={pt('loadError')}>
        {(raw) => {
          const o = raw as SupportOverview;
          return (
            <dl className="grid gap-4 sm:grid-cols-2">
              <Fact label={pt('plan')}>{o.subscription ? o.subscription.plan.name : '—'}</Fact>
              <Fact label={pt('employees')}>{of(o.usage.employees.used, o.usage.employees.limit)}</Fact>
              <Fact label={pt('aiRequests')}>{of(o.usage.ai_requests.used, o.usage.ai_requests.limit)}</Fact>
              <Fact label={pt('storage')}>{bytes(o.usage.storage_bytes)}</Fact>
              <Fact label={pt('departments')}>{o.departments}</Fact>
              <Fact label={pt('employeesByStatus')}>{Object.entries(o.employees_by_status).map(([k, v]) => `${k}: ${v}`).join(', ') || '—'}</Fact>
              <Fact label={pt('legalEntities')}>{o.legal_entities.map((e) => `${e.name} (${e.country_code})`).join(', ') || '—'}</Fact>
            </dl>
          );
        }}
      </ResourceView>
    );
  }
  const keys = sectionColumns[section];
  const result = view.data as Page<Record<string, unknown>> | undefined;
  const rows = (result?.data ?? []).map((r, i) => ({ ...r, __key: String(r.id ?? r.email ?? i) }));
  const show = (k: string, v: unknown) => {
    if (v === null || v === undefined || v === '') return '—';
    if (typeof v === 'boolean') return v ? pt('yes') : pt('no');
    if (Array.isArray(v)) return v.join(', ');
    if (k.endsWith('_at') && typeof v === 'string') return when(v);
    return String(v);
  };
  const columns: DataTableColumn<Record<string, unknown> & { __key: string }>[] = keys.map((k, i) => ({
    id: k, header: pt(`field.${k}`), primary: i === 0, hideBelow: i > 2 ? 'md' : undefined, cell: (r) => show(k, r[k]),
  }));
  return (
    <div className="rounded-md border border-line">
      <DataTable caption={pt(`supportSection.${section}`)} hideCaption density="compact" columns={columns} rows={rows} getRowId={(r) => r.__key}
        loading={view.loading && view.data === undefined} error={view.error ? errorMessage(view.error, pt('loadError')) : null} onRetry={view.reload}
        empty={<EmptyState title={pt('nothingToShow')} />} />
      <Pager meta={result?.meta} onPage={setPage} />
    </div>
  );
}

function Counts({ title, values, label }: { title: string; values: Record<string, number>; label: (k: string) => string }) {
  const { count } = usePlatformT();
  const entries = Object.entries(values);
  return (
    <Card title={title} headingLevel={3}>
      {entries.length === 0 ? <p className="text-sm text-fg-muted">—</p> : (
        <dl className="grid gap-2">
          {entries.map(([k, v]) => <div key={k} className="flex justify-between gap-4 text-sm"><dt className="text-fg-muted">{label(k)}</dt><dd className="tabular-nums text-fg">{count(v)}</dd></div>)}
        </dl>
      )}
    </Card>
  );
}

function Subscriptions() {
  const { pt, money, count } = usePlatformT();
  const source = usePlatformSource();
  const overview = useResource(useCallback((signal: AbortSignal) => source.overview(signal), [source]));
  if (overview.error instanceof ApiNotConnectedError) return <NotConnected />;
  return (
    <ResourceView resource={overview} empty={pt('nothingToShow')} errorLabel={pt('loadError')}>
      {(o) => (
        <div className="grid gap-4">
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <Card title={pt('activeTenants')} headingLevel={2}><p className="text-2xl font-semibold tabular-nums">{count(o.tenants.active)}</p></Card>
            <Card title={pt('suspendedTenants')} headingLevel={2}><p className="text-2xl font-semibold tabular-nums">{count(o.tenants.suspended)}</p></Card>
            <Card title={pt('trialsEnding')} headingLevel={2}><p className="text-2xl font-semibold tabular-nums">{count(o.trials_ending_7d)}</p></Card>
            <Card title={pt('cancelScheduled')} headingLevel={2}><p className="text-2xl font-semibold tabular-nums">{count(o.cancel_scheduled)}</p></Card>
          </div>
          <Card title={pt('mrr')} headingLevel={2} description={pt('mrrHint')}>
            {Object.keys(o.mrr_minor).length === 0 ? <p className="text-sm text-fg-muted">{pt('noMrr')}</p> : (
              <ul className="flex flex-wrap gap-6">
                {Object.entries(o.mrr_minor).map(([currency, minor]) => <li key={currency} className="text-xl font-semibold tabular-nums">{money(minor, currency)}</li>)}
              </ul>
            )}
          </Card>
          <div className="grid gap-4 md:grid-cols-3">
            <Counts title={pt('byStatus')} values={o.by_status} label={(k) => pt(`subscriptionStatus.${k}`)} />
            <Counts title={pt('byPlan')} values={o.by_plan} label={(k) => k} />
            <Counts title={pt('byProvider')} values={o.by_provider} label={(k) => pt(`provider.${k}`)} />
          </div>
        </div>
      )}
    </ResourceView>
  );
}

function UsageTab() {
  const { pt, bytes, of } = usePlatformT();
  const source = usePlatformSource();
  const [search, setSearch] = useState('');
  const [sort, setSort] = useState<UsageSort>('name');
  const [page, setPage] = useState(1);
  const usage = useResource(useCallback((signal: AbortSignal) => source.usage(search, sort, page, signal), [source, search, sort, page]));
  if (usage.error instanceof ApiNotConnectedError) return <NotConnected />;
  const over = (used: number, limit: number | null) => limit !== null && used > limit;
  const columns: DataTableColumn<UsageRow>[] = [
    { id: 'name', header: pt('organization'), primary: true, cell: (r) => r.name },
    { id: 'plan', header: pt('plan'), hideBelow: 'md', cell: (r) => r.plan ?? '—' },
    { id: 'employees', header: pt('employees'), cell: (r) => <span className={over(r.employees.used, r.employees.limit) ? 'font-medium text-danger-fg' : undefined}>{of(r.employees.used, r.employees.limit)}</span> },
    { id: 'ai', header: pt('aiRequests'), cell: (r) => <span className={over(r.ai_requests.used, r.ai_requests.limit) ? 'font-medium text-danger-fg' : undefined}>{of(r.ai_requests.used, r.ai_requests.limit)}</span> },
    { id: 'storage', header: pt('storage'), hideBelow: 'md', cell: (r) => bytes(r.storage_bytes) },
  ];
  return (
    <Card title={pt('usageTab')} headingLevel={2} padding="none" description={pt('usageHint')}>
      <div className="grid gap-3 border-b border-line p-4 md:grid-cols-2">
        <Input label={pt('search')} type="search" value={search} maxLength={100} onChange={(e) => { setSearch(e.target.value); setPage(1); }} />
        <Select label={pt('sortBy')} value={sort} onChange={(e) => { setSort(e.target.value as UsageSort); setPage(1); }}
          options={(['name', 'employees', 'ai_requests', 'storage_bytes'] as UsageSort[]).map((s) => ({ value: s, label: pt(`sort.${s}`) }))} />
      </div>
      <DataTable caption={pt('usageTab')} hideCaption columns={columns} rows={usage.data?.data ?? []} getRowId={(r) => r.id}
        loading={usage.loading && usage.data === undefined} error={usage.error ? errorMessage(usage.error, pt('loadError')) : null} onRetry={usage.reload}
        empty={<EmptyState title={pt('noTenants')} />} />
      <Pager meta={usage.data?.meta} onPage={setPage} />
    </Card>
  );
}

function Audit() {
  const { pt, when } = usePlatformT();
  const source = usePlatformSource();
  const can = useCan();
  const { toast } = useToast();
  const [query, setQuery] = useState<AuditQuery>({ event: '', outcome: '', from: '', to: '' });
  const [page, setPage] = useState(1);
  const [exporting, setExporting] = useState(false);
  const audit = useResource(useCallback((signal: AbortSignal) => source.audit(query, page, signal), [source, query, page]));
  if (audit.error instanceof ApiNotConnectedError) return <NotConnected />;
  const set = (k: keyof AuditQuery) => (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) => { setQuery((q) => ({ ...q, [k]: e.target.value })); setPage(1); };
  const download = async () => {
    setExporting(true);
    try {
      await source.exportAudit(query);
    } catch (e) {
      toast({ title: errorMessage(e, pt('exportFailed')), tone: 'danger' });
    } finally {
      setExporting(false);
    }
  };
  const columns: DataTableColumn<AuditEntry>[] = [
    { id: 'event', header: pt('event'), primary: true, cell: (e) => <span className="font-mono text-xs">{e.event}</span> },
    { id: 'time', header: pt('time'), cell: (e) => when(e.created_at) },
    { id: 'actor', header: pt('actor'), hideBelow: 'md', cell: (e) => e.actor?.name ?? '—' },
    { id: 'outcome', header: pt('outcome'), cell: (e) => <Badge tone={e.outcome === 'success' ? 'success' : 'danger'} dot size="sm">{pt(`outcomeValue.${e.outcome}`)}</Badge> },
    { id: 'ip', header: pt('ip'), hideBelow: 'lg', cell: (e) => e.ip_address ?? '—' },
  ];
  return (
    <Card title={pt('auditTab')} headingLevel={2} padding="none" description={pt('auditHint')}
      actions={can(P.PlatformAuditExport) ? <Button size="sm" variant="secondary" loading={exporting} onClick={download}>{pt('exportCsv')}</Button> : undefined}>
      <div className="grid gap-3 border-b border-line p-4 md:grid-cols-4">
        <Input label={pt('event')} value={query.event} maxLength={81} hint={pt('eventHint')} onChange={set('event')} />
        <Select label={pt('outcome')} value={query.outcome} placeholder={pt('allOutcomes')} onChange={set('outcome')}
          options={['success', 'failure'].map((o) => ({ value: o, label: pt(`outcomeValue.${o}`) }))} />
        <Input label={pt('from')} type="date" value={query.from} onChange={set('from')} />
        <Input label={pt('to')} type="date" value={query.to} onChange={set('to')} />
      </div>
      <DataTable caption={pt('auditTab')} hideCaption columns={columns} rows={audit.data?.data ?? []} getRowId={(e) => e.id}
        loading={audit.loading && audit.data === undefined} error={audit.error ? errorMessage(audit.error, pt('loadError')) : null} onRetry={audit.reload}
        empty={<EmptyState title={pt('noAudit')} />} />
      <Pager meta={audit.data?.meta} onPage={setPage} />
    </Card>
  );
}

function Health() {
  const { pt, when, count } = usePlatformT();
  const source = usePlatformSource();
  const health = useResource(useCallback((signal: AbortSignal) => source.health(signal), [source]));
  if (health.error instanceof ApiNotConnectedError) return <NotConnected />;
  const n = (v: number | null) => (v === null ? '—' : count(v));
  const ms = (v: number | null) => (v === null ? '—' : pt('ms', { value: v }));
  const rows = (r: HealthReport): Array<{ id: keyof HealthReport['checks']; facts: Array<[string, string]> }> => [
    { id: 'database', facts: [[pt('latency'), ms(r.checks.database.latency_ms)]] },
    { id: 'cache', facts: [[pt('latency'), ms(r.checks.cache.latency_ms)]] },
    { id: 'queue', facts: [[pt('connection'), r.checks.queue.connection], [pt('pendingJobs'), n(r.checks.queue.pending)], [pt('failedJobs'), n(r.checks.queue.failed)], [pt('failed24h'), n(r.checks.queue.failed_24h)]] },
    { id: 'scheduler', facts: [[pt('lastRun'), r.checks.scheduler.last_run_at ? when(r.checks.scheduler.last_run_at) : pt('never')]] },
    { id: 'webhooks', facts: [[pt('processed24h'), n(r.checks.webhooks.processed_24h)], [pt('ignored24h'), n(r.checks.webhooks.ignored_24h)],
      [pt('unmatched24h'), n(r.checks.webhooks.unmatched_24h)], [pt('rejected24h'), n(r.checks.webhooks.rejected_24h)],
      [pt('lastReceived'), r.checks.webhooks.last_received_at ? when(r.checks.webhooks.last_received_at) : pt('never')]] },
    { id: 'data', facts: [[pt('exportsFailed24h'), n(r.checks.data.exports_failed_24h)], [pt('deletionsScheduled'), n(r.checks.data.deletions_scheduled)], [pt('deletionsInProgress'), n(r.checks.data.deletions_in_progress)]] },
  ];
  return (
    <ResourceView resource={health} empty={pt('nothingToShow')} errorLabel={pt('loadError')}>
      {(r) => (
        <div className="grid gap-4">
          <Card title={pt('overall')} headingLevel={2} description={pt('checkedAt', { date: when(r.checked_at) })}
            actions={<Button size="sm" variant="secondary" loading={health.loading} onClick={health.reload}>{pt('refresh')}</Button>}>
            <Badge tone={checkTone[r.status]} dot>{pt(`check.${r.status}`)}</Badge>
          </Card>
          <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
            {rows(r).map((row) => (
              <Card key={row.id} title={pt(`checkName.${row.id}`)} headingLevel={3} actions={<Badge tone={checkTone[r.checks[row.id].status]} dot size="sm">{pt(`check.${r.checks[row.id].status}`)}</Badge>}>
                <dl className="grid gap-2">
                  {row.facts.map(([label, value]) => <div key={label} className="flex justify-between gap-4 text-sm"><dt className="text-fg-muted">{label}</dt><dd className="tabular-nums text-fg">{value}</dd></div>)}
                </dl>
              </Card>
            ))}
          </div>
        </div>
      )}
    </ResourceView>
  );
}
