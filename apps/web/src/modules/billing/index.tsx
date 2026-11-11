import { useCallback, useEffect, useState } from 'react';
import { Permission as P } from '@wingora/permissions';
import type { MessageKey } from '@wingora/localization';
import { ApiNotConnectedError } from '@/api/dashboard';
import {
  useBillingSource, type DataExport, type Interval, type Invoice, type MfaProof, type Plan, type Subscription, type SupportGrant,
} from '@/api/billing';
import { Badge, Button, Card, Checkbox, ConfirmationDialog, DataTable, EmptyState, Input, Modal, Select, Tabs, useToast, type DataTableColumn } from '@/components/ui';
import { useSession } from '@/auth/session';
import { PageHeader } from '@/shell/page-header';
import { useI18n } from '@/i18n/i18n';
import { errorMessage, FormError, formError, ResourceView, useResource, validationErrors } from '@/modules/employees/shared';
import { Pager } from '@/modules/attendance';
import { useAction, useCan, useSave } from '@/modules/recruitment/shared';

const INTERVALS: Interval[] = ['monthly', 'annual'];
const HOURS = [1, 4, 8, 24, 72];
/** How often an export that is still being prepared is re-checked while the Account tab is open. */
const EXPORT_POLL_MS = 3000;
type Tone = 'neutral' | 'warning' | 'success' | 'danger' | 'accent';
const statusTone: Record<string, Tone> = { trialing: 'accent', active: 'success', past_due: 'warning', unpaid: 'danger', canceled: 'neutral', incomplete: 'warning' };
const invoiceTone: Record<Invoice['status'], Tone> = { draft: 'neutral', open: 'warning', paid: 'success', void: 'neutral', uncollectible: 'danger' };
const exportTone: Record<DataExport['status'], Tone> = { queued: 'neutral', running: 'accent', ready: 'success', failed: 'danger', expired: 'neutral' };

export function useBillingT() {
  const { t, locale } = useI18n();
  const bt = (key: string, params?: Record<string, string | number>) => t(`billing.${key}` as MessageKey, params);
  const date = (iso: string) => new Intl.DateTimeFormat(locale, { dateStyle: 'medium' }).format(new Date(iso));
  const when = (iso: string) => new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(iso));
  const money = (minor: number, currency: string) => new Intl.NumberFormat(locale, { style: 'currency', currency }).format(minor / 100);
  const count = (n: number) => new Intl.NumberFormat(locale).format(n);
  const bytes = (n: number) => {
    const units = ['byte', 'kilobyte', 'megabyte', 'gigabyte', 'terabyte'];
    const i = n > 0 ? Math.min(units.length - 1, Math.floor(Math.log(n) / Math.log(1024))) : 0;
    return new Intl.NumberFormat(locale, { style: 'unit', unit: units[i], maximumFractionDigits: 1 }).format(n / 1024 ** i);
  };
  return { bt, t, date, when, money, count, bytes };
}

/** A six-digit code goes as `code`; anything longer is treated as a recovery code. */
const proofOf = (value: string): MfaProof => (/^\d{6}$/.test(value.trim()) ? { code: value.trim() } : { recovery_code: value.trim() });

function NotConnected() {
  const { bt } = useBillingT();
  return <EmptyState title={bt('notConnectedTitle')} description={bt('notConnectedDescription')} />;
}

export default function Billing() {
  const { bt, t } = useBillingT();
  const can = useCan();
  const tabs = [
    can(P.BillingView) && { id: 'plan', label: bt('planTab'), content: <PlanTab /> },
    can(P.BillingView) && { id: 'invoices', label: bt('invoicesTab'), content: <Invoices /> },
    can(P.AccountManage) && { id: 'account', label: bt('accountTab'), content: <Account /> },
  ].filter((x): x is { id: string; label: string; content: React.ReactElement } => Boolean(x));
  return (
    <>
      <PageHeader title={t('nav.module.billing')} description={bt('subtitle')} />
      {tabs.length > 0 ? <Tabs label={t('nav.module.billing')} tabs={tabs} /> : <EmptyState title={bt('nothingToShow')} />}
    </>
  );
}

function Meter({ label, used, limit, format }: { label: string; used: number; limit: number | null; format: (n: number) => string }) {
  const { bt } = useBillingT();
  const pct = limit ? Math.min(100, Math.round((used / limit) * 100)) : 0;
  return (
    <div className="flex flex-col gap-1.5">
      <div className="flex items-baseline justify-between gap-2 text-sm">
        <span className="font-medium text-fg">{label}</span>
        <span className="tabular-nums text-fg-secondary">{limit === null ? bt('usedUnlimited', { used: format(used) }) : bt('usedOf', { used: format(used), limit: format(limit) })}</span>
      </div>
      {limit !== null && (
        <div role="progressbar" aria-label={label} aria-valuemin={0} aria-valuemax={100} aria-valuenow={pct} className="h-2 overflow-hidden rounded-full bg-surface-muted">
          <div className={`h-full rounded-full ${pct >= 100 ? 'bg-danger-solid' : pct >= 80 ? 'bg-warning-solid' : 'bg-accent'}`} style={{ width: `${pct}%` }} />
        </div>
      )}
    </div>
  );
}

function PlanTab() {
  const { bt, date, count, bytes, money } = useBillingT();
  const source = useBillingSource();
  const can = useCan();
  const { busy, run } = useAction();
  const [confirm, setConfirm] = useState<null | 'cancel' | { plan: Plan; interval: Interval }>(null);
  const [interval, setInterval] = useState<Interval>('monthly');
  const subscription = useResource(useCallback((signal: AbortSignal) => source.subscription(signal), [source]));
  const plans = useResource(useCallback((signal: AbortSignal) => source.plans(signal), [source]));
  const manage = can(P.BillingManage);
  if (subscription.error instanceof ApiNotConnectedError) return <NotConnected />;

  return (
    <ResourceView resource={subscription} empty={bt('nothingToShow')} errorLabel={bt('loadError')}>
      {(s: Subscription) => {
        const current = plans.data?.find((p) => p.code === s.plan.code);
        const stripeLive = s.provider === 'stripe' && s.status !== 'canceled';
        const choose = (plan: Plan) => {
          if (stripeLive) setConfirm({ plan, interval });
          else void run('checkout', async () => source.redirect(await source.checkout(plan.code, interval)));
        };
        const reload = () => { subscription.reload(); plans.reload(); };
        return (
          <div className="flex flex-col gap-6">
            <Card title={bt('currentPlan')} headingLevel={2}
              actions={<Badge tone={statusTone[s.status] ?? 'neutral'} dot size="sm">{bt(`status.${s.status}`)}</Badge>}>
              <div className="flex flex-col gap-4">
                <div>
                  <p className="text-xl font-semibold text-fg">{s.plan.name}</p>
                  <ul className="mt-1 flex flex-col gap-0.5 text-sm text-fg-secondary">
                    {s.provider === 'trial' && s.trial_ends_at && <li>{bt('trialEnds', { date: date(s.trial_ends_at) })}</li>}
                    {s.interval && <li>{bt(`interval.${s.interval}`)}</li>}
                    {s.current_period_end && <li>{bt(s.cancel_at_period_end ? 'endsOn' : 'renewsOn', { date: date(s.current_period_end) })}</li>}
                    {s.grace_ends_at && <li className="text-warning-fg">{bt('graceEnds', { date: date(s.grace_ends_at) })}</li>}
                    {s.pending_change && <li>{bt('pendingChange', { plan: s.pending_change.plan.name, date: date(s.pending_change.effective_at) })}</li>}
                    {s.provider === 'manual' && <li>{bt('manualPlan')}</li>}
                  </ul>
                </div>
                {s.access.mode === 'read_only' && <p role="status" className="rounded-md bg-warning-subtle p-3 text-sm text-warning-fg">{bt(`access.${s.access.reason ?? 'canceled'}`)}</p>}
                {s.access.reason === 'payment_failed' && <p role="status" className="rounded-md bg-warning-subtle p-3 text-sm text-warning-fg">{bt('access.payment_failed')}</p>}
                <div className="grid gap-4 md:grid-cols-3">
                  <Meter label={bt('employees')} used={s.usage.employees.used} limit={s.usage.employees.limit} format={count} />
                  <Meter label={bt('aiRequests')} used={s.usage.ai_requests.used} limit={s.usage.ai_requests.limit} format={count} />
                  <Meter label={bt('storage')} used={s.usage.storage_bytes} limit={null} format={bytes} />
                </div>
                {manage && (
                  <div className="flex flex-wrap gap-2">
                    {s.has_payment_account && s.payments_enabled && (
                      <Button variant="secondary" loading={busy === 'portal'} onClick={() => run('portal', async () => source.redirect(await source.portal()))}>{bt('managePayment')}</Button>
                    )}
                    {stripeLive && !s.cancel_at_period_end && <Button variant="secondary" onClick={() => setConfirm('cancel')}>{bt('cancelPlan')}</Button>}
                    {stripeLive && s.cancel_at_period_end && (
                      <Button variant="secondary" loading={busy === 'resume'} onClick={() => run('resume', () => source.resume().then(reload), bt('resumed'))}>{bt('resumePlan')}</Button>
                    )}
                  </div>
                )}
                <p className="text-xs text-fg-muted">{bt('cardNote')}</p>
              </div>
            </Card>

            <Card title={bt('plans')} headingLevel={2} description={bt(stripeLive ? 'plansChangeHint' : 'plansHint')}
              actions={<Select label={bt('billingPeriod')} hideLabel value={interval} onChange={(e) => setInterval(e.target.value as Interval)}
                options={INTERVALS.map((i) => ({ value: i, label: bt(`interval.${i}`) }))} />}>
              {plans.error ? <p role="alert" className="text-sm text-danger-fg">{errorMessage(plans.error, bt('loadError'))}</p> : (
                <ul className="grid gap-4 md:grid-cols-3">
                  {(plans.data ?? []).map((p) => {
                    const price = p.prices[interval];
                    const isCurrent = p.code === s.plan.code && (s.interval === interval || !stripeLive);
                    const disabled = !manage || s.provider === 'manual' || !s.payments_enabled || !price.available || (isCurrent && stripeLive);
                    return (
                      <li key={p.code} className={`flex flex-col gap-3 rounded-(--card-radius) border p-4 ${p.code === s.plan.code ? 'border-accent' : 'border-line'}`}>
                        <div className="flex items-start justify-between gap-2">
                          <h3 className="text-md font-semibold text-fg">{p.name}</h3>
                          {p.code === s.plan.code && <Badge tone="accent" size="sm">{bt('current')}</Badge>}
                        </div>
                        <p className="text-lg font-semibold tabular-nums text-fg">
                          {price.amount === null ? bt('priceNotSet') : bt(`per.${interval}`, { price: money(price.amount, p.currency) })}
                        </p>
                        {p.description && <p className="text-sm text-fg-secondary">{p.description}</p>}
                        <ul className="flex flex-col gap-1 text-sm text-fg-secondary">
                          <li>{p.employee_limit === null ? bt('unlimitedEmployees') : bt('employeeLimit', { count: count(p.employee_limit) })}</li>
                          <li>{p.ai_requests_monthly === null ? bt('unlimitedAi') : bt('aiLimit', { count: count(p.ai_requests_monthly) })}</li>
                          <li>{bt('modules', { list: p.features.map((f) => bt(`feature.${f}`)).join(', ') })}</li>
                        </ul>
                        {manage && (
                          <Button className="mt-auto" variant={p.code === s.plan.code ? 'secondary' : 'primary'} disabled={disabled} loading={busy === 'checkout'} onClick={() => choose(p)}>
                            {stripeLive ? (current && p.rank < current.rank ? bt('downgrade') : bt('switch')) : bt('subscribe')}
                          </Button>
                        )}
                      </li>
                    );
                  })}
                </ul>
              )}
              {manage && !s.payments_enabled && <p className="mt-4 text-sm text-fg-muted">{bt('paymentsOff')}</p>}
            </Card>

            <ConfirmationDialog open={confirm === 'cancel'} tone="danger" title={bt('cancelTitle')}
              description={bt('cancelDescription', { date: s.current_period_end ? date(s.current_period_end) : '—' })} confirmLabel={bt('cancelPlan')}
              onCancel={() => setConfirm(null)} onConfirm={async () => { if (await run('cancel', () => source.cancel(), bt('canceled'))) { setConfirm(null); reload(); } }} />
            {confirm && confirm !== 'cancel' && (() => {
              const downgrade = current !== undefined && confirm.plan.rank < current.rank;
              return (
                <ConfirmationDialog open tone="primary" title={bt('changeTitle', { plan: confirm.plan.name })}
                  description={bt(downgrade ? 'downgradeDescription' : 'upgradeDescription')} confirmLabel={bt('confirmChange')} onCancel={() => setConfirm(null)}
                  onConfirm={async () => {
                    if (await run('change', () => source.change(confirm.plan.code, confirm.interval, s.version), bt(downgrade ? 'downgradeScheduled' : 'changed'))) setConfirm(null);
                    reload();
                  }} />
              );
            })()}
          </div>
        );
      }}
    </ResourceView>
  );
}

function Invoices() {
  const { bt, date, money } = useBillingT();
  const source = useBillingSource();
  const [page, setPage] = useState(1);
  const invoices = useResource(useCallback((signal: AbortSignal) => source.invoices(page, signal), [source, page]));
  if (invoices.error instanceof ApiNotConnectedError) return <NotConnected />;
  const columns: DataTableColumn<Invoice>[] = [
    { id: 'number', header: bt('invoice'), primary: true, cell: (i) => i.number ?? '—' },
    { id: 'period', header: bt('period'), hideBelow: 'md', cell: (i) => (i.period_start && i.period_end ? `${date(i.period_start)} – ${date(i.period_end)}` : '—') },
    { id: 'amount', header: bt('amount'), cell: (i) => <span className="tabular-nums">{money(i.amount_due, i.currency)}</span> },
    { id: 'status', header: bt('statusLabel'), cell: (i) => <Badge tone={invoiceTone[i.status]} dot size="sm">{bt(`invoiceStatus.${i.status}`)}</Badge> },
    { id: 'links', header: bt('documents'), cell: (i) => (
      <span className="flex flex-wrap gap-3">
        {i.hosted_invoice_url && <a className="text-fg-link hover:underline" href={i.hosted_invoice_url} target="_blank" rel="noopener noreferrer">{bt(i.status === 'open' ? 'pay' : 'view')}</a>}
        {i.invoice_pdf_url && <a className="text-fg-link hover:underline" href={i.invoice_pdf_url} target="_blank" rel="noopener noreferrer">{bt('pdf')}</a>}
      </span>
    ) },
  ];
  return (
    <Card title={bt('invoicesTab')} headingLevel={2} padding="none" description={bt('invoicesHint')}>
      <DataTable caption={bt('invoicesTab')} hideCaption columns={columns} rows={invoices.data?.data ?? []} getRowId={(i) => i.id}
        loading={invoices.loading && invoices.data === undefined} error={invoices.error ? errorMessage(invoices.error, bt('loadError')) : null} onRetry={invoices.reload}
        empty={<EmptyState title={bt('noInvoices')} />} />
      <Pager meta={invoices.data?.meta} onPage={setPage} />
    </Card>
  );
}

function Account() {
  return (
    <div className="flex flex-col gap-6">
      <SupportAccess />
      <Exports />
      <Deletion />
    </div>
  );
}

function SupportAccess() {
  const { bt, when } = useBillingT();
  const source = useBillingSource();
  const { busy, run } = useAction();
  const [granting, setGranting] = useState(false);
  const grants = useResource(useCallback((signal: AbortSignal) => source.supportGrants(signal), [source]));
  if (grants.error instanceof ApiNotConnectedError) return <NotConnected />;
  const active = grants.data?.find((g) => g.active);
  return (
    <Card title={bt('supportTitle')} headingLevel={2} description={bt('supportHint')}
      actions={!active && grants.data && <Button variant="secondary" onClick={() => setGranting(true)}>{bt('grantSupport')}</Button>}>
      {grants.error ? <p role="alert" className="text-sm text-danger-fg">{errorMessage(grants.error, bt('loadError'))}</p> : (
        <div className="flex flex-col gap-3 text-sm">
          {active ? (
            <div className="flex flex-wrap items-center justify-between gap-3 rounded-md bg-accent-subtle p-3">
              <div>
                <p className="font-medium text-fg">{bt('supportActive', { date: when(active.expires_at) })}</p>
                <p className="text-fg-secondary">{active.reason}{active.include_payroll ? ` · ${bt('includesPayroll')}` : ''}</p>
                {active.last_used_at && <p className="text-fg-muted">{bt('lastUsed', { date: when(active.last_used_at) })}</p>}
              </div>
              <Button variant="secondary" loading={busy === active.id} onClick={() => run(active.id, () => source.revokeSupport(active.id).then(grants.reload), bt('revoked'))}>{bt('revoke')}</Button>
            </div>
          ) : <p className="text-fg-secondary">{bt('supportNone')}</p>}
          <p className="text-fg-muted">{bt('supportNeverSees')}</p>
          <History grants={(grants.data ?? []).filter((g) => !g.active)} />
        </div>
      )}
      {granting && <GrantDialog onClose={() => setGranting(false)} onDone={() => { setGranting(false); grants.reload(); }} />}
    </Card>
  );
}

function History({ grants }: { grants: SupportGrant[] }) {
  const { bt, when } = useBillingT();
  if (grants.length === 0) return null;
  return (
    <details>
      <summary className="cursor-pointer text-fg-link">{bt('supportHistory')}</summary>
      <ul className="mt-2 flex flex-col gap-1 text-fg-secondary">
        {grants.map((g) => <li key={g.id}>{g.created_at ? when(g.created_at) : '—'} · {g.reason} · {bt(g.revoked_at ? 'wasRevoked' : 'expired')}</li>)}
      </ul>
    </details>
  );
}

function GrantDialog({ onClose, onDone }: { onClose: () => void; onDone: () => void }) {
  const { bt, t } = useBillingT();
  const source = useBillingSource();
  const { toast } = useToast();
  const [hours, setHours] = useState('4');
  const [reason, setReason] = useState('');
  const [payroll, setPayroll] = useState(false);
  const { error, busy, save } = useSave();
  const fields = validationErrors(error);
  const submit = () => save(async () => {
    await source.grantSupport({ hours: Number(hours), reason: reason.trim(), include_payroll: payroll });
    toast({ title: bt('granted'), tone: 'success' });
    onDone();
  });
  return (
    <Modal open onClose={onClose} title={bt('grantSupport')} description={bt('grantHint')}
      footer={<><Button variant="secondary" onClick={onClose}>{t('ui.cancel')}</Button><Button loading={busy} disabled={reason.trim() === ''} onClick={submit}>{bt('grant')}</Button></>}>
      <div className="grid gap-4">
        <Select label={bt('duration')} value={hours} onChange={(e) => setHours(e.target.value)} error={fields.hours}
          options={HOURS.map((h) => ({ value: String(h), label: bt('hours', { count: h }) }))} />
        <Input label={bt('reason')} required maxLength={500} value={reason} error={fields.reason} hint={bt('reasonHint')} onChange={(e) => setReason(e.target.value)} />
        <Checkbox label={bt('allowPayroll')} description={bt('allowPayrollHint')} checked={payroll} onChange={(e) => setPayroll(e.target.checked)} />
        <FormError message={error ? formError(error, bt('saveFailed')) : null} />
      </div>
    </Modal>
  );
}

function MfaField({ value, onChange, error }: { value: string; onChange: (v: string) => void; error?: string }) {
  const { bt } = useBillingT();
  return <Input label={bt('mfaCode')} required autoComplete="one-time-code" maxLength={40} hint={bt('mfaHint')} value={value} error={error} onChange={(e) => onChange(e.target.value)} />;
}
function useMfaReady() {
  const { me } = useSession();
  return me?.user.mfa_enabled ?? false;
}

function Exports() {
  const { bt, when, bytes } = useBillingT();
  const source = useBillingSource();
  const { busy, run } = useAction();
  const [requesting, setRequesting] = useState(false);
  const mfa = useMfaReady();
  const exports = useResource(useCallback((signal: AbortSignal) => source.exports(signal), [source]));
  const pending = exports.data?.some((e) => e.status === 'queued' || e.status === 'running') ?? false;
  const reload = exports.reload;
  useEffect(() => {
    if (!pending) return;
    const id = window.setTimeout(reload, EXPORT_POLL_MS);
    return () => window.clearTimeout(id);
  }, [pending, reload, exports.data]);
  if (exports.error instanceof ApiNotConnectedError) return null;
  return (
    <Card title={bt('exportTitle')} headingLevel={2} description={bt('exportHint')}
      actions={<Button variant="secondary" disabled={!mfa || pending} onClick={() => setRequesting(true)}>{bt('requestExport')}</Button>}>
      {!mfa && <p className="mb-3 text-sm text-warning-fg">{bt('mfaRequired')}</p>}
      {exports.error ? <p role="alert" className="text-sm text-danger-fg">{errorMessage(exports.error, bt('loadError'))}</p> : (
        (exports.data ?? []).length === 0 ? <p className="text-sm text-fg-secondary">{bt('noExports')}</p> : (
          <ul className="flex flex-col divide-y divide-line text-sm">
            {(exports.data ?? []).map((e) => (
              <li key={e.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
                <span className="flex flex-wrap items-center gap-2">
                  <Badge tone={exportTone[e.status]} dot size="sm">{bt(`exportStatus.${e.status}`)}</Badge>
                  <span className="text-fg-secondary">{e.created_at ? when(e.created_at) : '—'}{e.size ? ` · ${bytes(e.size)}` : ''}</span>
                  {e.status === 'ready' && e.expires_at && <span className="text-fg-muted">{bt('availableUntil', { date: when(e.expires_at) })}</span>}
                </span>
                {e.status === 'ready' && <Button size="sm" variant="secondary" loading={busy === e.id} onClick={() => run(e.id, () => source.downloadExport(e.id))}>{bt('download')}</Button>}
              </li>
            ))}
          </ul>
        )
      )}
      {requesting && <ExportDialog onClose={() => setRequesting(false)} onDone={() => { setRequesting(false); exports.reload(); }} />}
    </Card>
  );
}

function ExportDialog({ onClose, onDone }: { onClose: () => void; onDone: () => void }) {
  const { bt, t } = useBillingT();
  const source = useBillingSource();
  const { toast } = useToast();
  const [code, setCode] = useState('');
  const { error, busy, save } = useSave();
  const fields = validationErrors(error);
  const submit = () => save(async () => {
    await source.requestExport(proofOf(code));
    toast({ title: bt('exportQueued'), tone: 'success' });
    onDone();
  });
  return (
    <Modal open onClose={onClose} title={bt('requestExport')} description={bt('exportDialogHint')}
      footer={<><Button variant="secondary" onClick={onClose}>{t('ui.cancel')}</Button><Button loading={busy} disabled={code.trim() === ''} onClick={submit}>{bt('requestExport')}</Button></>}>
      <div className="grid gap-4">
        <MfaField value={code} onChange={setCode} error={fields.code ?? fields.recovery_code} />
        <FormError message={error ? formError(error, bt('saveFailed')) : null} />
      </div>
    </Modal>
  );
}

function Deletion() {
  const { bt, date } = useBillingT();
  const source = useBillingSource();
  const { busy, run } = useAction();
  const [deleting, setDeleting] = useState(false);
  const mfa = useMfaReady();
  const state = useResource(useCallback((signal: AbortSignal) => source.deletion(signal), [source]));
  if (state.error instanceof ApiNotConnectedError) return null;
  const request = state.data?.request ?? null;
  return (
    <Card title={bt('deleteTitle')} headingLevel={2} description={bt('deleteHint', { days: state.data?.waiting_days ?? 30 })} className="border-danger-line">
      {state.error ? <p role="alert" className="text-sm text-danger-fg">{errorMessage(state.error, bt('loadError'))}</p> : (
        <div className="flex flex-col gap-3 text-sm">
          {state.data?.legal_hold && <p role="status" className="text-warning-fg">{bt('legalHold')}</p>}
          {request?.status === 'scheduled' && (
            <div className="flex flex-wrap items-center justify-between gap-3 rounded-md bg-danger-subtle p-3">
              <p className="font-medium text-danger-fg">{bt('deletionScheduled', { date: date(request.scheduled_for) })}</p>
              <Button variant="secondary" loading={busy === 'cancel'} onClick={() => run('cancel', () => source.cancelDeletion().then(state.reload), bt('deletionCanceled'))}>{bt('cancelDeletion')}</Button>
            </div>
          )}
          {request?.status === 'purging' && <p role="status" className="font-medium text-danger-fg">{bt('deletionInProgress')}</p>}
          {!request && state.data && (
            <>
              {!mfa && <p className="text-warning-fg">{bt('mfaRequired')}</p>}
              <div><Button variant="danger" disabled={!mfa || state.data.legal_hold} onClick={() => setDeleting(true)}>{bt('deleteOrganization')}</Button></div>
            </>
          )}
        </div>
      )}
      {deleting && <DeleteDialog onClose={() => setDeleting(false)} onDone={() => { setDeleting(false); state.reload(); }} />}
    </Card>
  );
}

function DeleteDialog({ onClose, onDone }: { onClose: () => void; onDone: () => void }) {
  const { bt, t } = useBillingT();
  const { me } = useSession();
  const source = useBillingSource();
  const { toast } = useToast();
  const [name, setName] = useState('');
  const [code, setCode] = useState('');
  const { error, busy, save } = useSave();
  const fields = validationErrors(error);
  const orgName = me?.organization?.name ?? '';
  const matches = name.trim().toLowerCase() === orgName.trim().toLowerCase() && orgName !== '';
  const submit = () => save(async () => {
    await source.requestDeletion(name.trim(), proofOf(code));
    toast({ title: bt('deletionRequested'), tone: 'success' });
    onDone();
  });
  return (
    <Modal open onClose={onClose} role="alertdialog" title={bt('deleteOrganization')} description={bt('deleteDialogHint')}
      footer={<><Button variant="secondary" onClick={onClose}>{t('ui.cancel')}</Button><Button variant="danger" loading={busy} disabled={!matches || code.trim() === ''} onClick={submit}>{bt('scheduleDeletion')}</Button></>}>
      <div className="grid gap-4">
        <Input label={bt('typeName', { name: orgName })} required autoComplete="off" value={name} error={fields.confirm_name} onChange={(e) => setName(e.target.value)} />
        <MfaField value={code} onChange={setCode} error={fields.code ?? fields.recovery_code} />
        <FormError message={error ? formError(error, bt('saveFailed')) : null} />
      </div>
    </Modal>
  );
}
