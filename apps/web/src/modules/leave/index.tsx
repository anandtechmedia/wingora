import { useCallback, useEffect, useState } from 'react';
import { Permission as P } from '@wingora/permissions';
import { ApiNotConnectedError } from '@/api/dashboard';
import { useTimeSource, type LeavePolicyInput, type LeaveRequestItem, type LeaveType, type LeaveTypeInput, type RequestScope } from '@/api/time';
import { Badge, Button, CalendarIcon, Card, Checkbox, DataTable, DatePicker, EmptyState, Input, Modal, Select, Tabs, useToast, type DataTableColumn } from '@/components/ui';
import { useSession } from '@/auth/session';
import { PageHeader } from '@/shell/page-header';
import { errorMessage, FormError, formError, ResourceView, useResource, validationErrors } from '@/modules/employees/shared';
import { NotConnected, Pager } from '@/modules/attendance';
import { DecisionDialog, RequestStatusBadge, TextArea, useTimeT } from '@/modules/time/shared';

const thisYear = new Date().getFullYear();
const fmt = (n: number) => (Number.isInteger(n) ? String(n) : n.toFixed(2).replace(/0$/, ''));

export default function Leave() {
  const { tt, t } = useTimeT();
  const { permissions } = useSession();
  const can = (...p: string[]) => p.some((x) => permissions.has(x));
  const tabs = [
    can(P.LeaveView) && { id: 'mine', label: tt('myLeave'), content: <MyLeave /> },
    can(P.LeaveApprove, P.LeaveManage) && { id: 'approvals', label: tt('approvals'), content: <RequestsCard scope="approvals" title={tt('pendingLeave')} /> },
    can(P.LeaveApprove, P.LeaveManage) && { id: 'all', label: tt('leaveHistory'), content: <RequestsCard scope="all" title={tt('teamLeave')} /> },
    can(P.LeaveView) && { id: 'holidays', label: tt('holidays'), content: <Holidays /> },
    can(P.LeaveConfigure) && { id: 'settings', label: tt('leaveSettings'), content: <LeaveSettings /> },
  ].filter((x): x is { id: string; label: string; content: React.ReactElement } => Boolean(x));
  return (
    <>
      <PageHeader title={t('nav.module.leave')} description={tt('leaveSubtitle')} />
      {tabs.length > 0 ? <Tabs label={t('nav.module.leave')} tabs={tabs} /> : <EmptyState title={tt('nothingToShow')} />}
    </>
  );
}

function MyLeave() {
  const { tt } = useTimeT();
  const source = useTimeSource();
  const { permissions } = useSession();
  const [year, setYear] = useState(thisYear);
  const [apply, setApply] = useState(false);
  const [version, setVersion] = useState(0);
  const balances = useResource(useCallback((signal: AbortSignal) => { void version; return source.balances(year, signal); }, [source, year, version]));
  const ledger = useResource(useCallback((signal: AbortSignal) => { void version; return source.ledger(year, signal); }, [source, year, version]));
  const refresh = () => setVersion((v) => v + 1);
  return (
    <div className="space-y-6">
      <Card title={tt('balances')} headingLevel={2} actions={<div className="flex items-end gap-2">
        <Select label={tt('year')} hideLabel value={String(year)} onChange={(e) => setYear(Number(e.target.value))}
          options={[thisYear - 1, thisYear, thisYear + 1].map((y) => ({ value: String(y), label: String(y) }))} />
        {permissions.has(P.LeaveApply) && <Button onClick={() => setApply(true)}>{tt('applyLeave')}</Button>}
      </div>}>
        <ResourceView resource={balances} empty={tt('noLeaveTypes')} isEmpty={(d) => d.length === 0}>
          {(list) => <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">{list.map((b) => (
            <li key={b.leave_type.id} className="rounded-lg border border-line p-4">
              <div className="flex items-center justify-between gap-2"><span className="font-medium text-fg">{b.leave_type.name}</span>{!b.leave_type.is_paid && <Badge size="sm">{tt('unpaid')}</Badge>}</div>
              <p className="mt-2 text-2xl font-semibold text-fg">{fmt(b.available)} <span className="text-sm font-normal text-fg-muted">{tt('daysAvailable')}</span></p>
              <dl className="mt-2 grid grid-cols-2 gap-x-3 gap-y-1 text-xs text-fg-muted">
                <dt>{tt('entitled')}</dt><dd className="text-right">{fmt(b.entitled)}</dd>
                {b.carried_forward > 0 && <><dt>{tt('carriedForward')}</dt><dd className="text-right">{fmt(b.carried_forward)}</dd></>}
                {b.adjustments !== 0 && <><dt>{tt('adjustments')}</dt><dd className="text-right">{fmt(b.adjustments)}</dd></>}
                <dt>{tt('used')}</dt><dd className="text-right">{fmt(b.used)}</dd>
                {b.pending > 0 && <><dt>{tt('pending')}</dt><dd className="text-right">{fmt(b.pending)}</dd></>}
                {b.expired > 0 && <><dt>{tt('expired')}</dt><dd className="text-right">{fmt(b.expired)}</dd></>}
              </dl>
            </li>))}</ul>}
        </ResourceView>
      </Card>
      <RequestsCard scope="mine" title={tt('myRequests')} version={version} onChanged={refresh} />
      <Card title={tt('balanceHistory')} headingLevel={2}>
        <ResourceView resource={ledger} empty={tt('noLedger')} isEmpty={(d) => d.data.length === 0}>
          {(page) => <ul className="divide-y divide-line text-sm">{page.data.map((e) => (
            <li key={e.id} className="flex flex-wrap justify-between gap-2 py-2">
              <span><span className="font-medium text-fg">{e.leave_type.name}</span> · {tt(`entry.${e.entry_type}`)} · {e.effective_on}{e.note ? ` · ${e.note}` : ''}</span>
              <span className={e.amount < 0 ? 'text-danger-fg' : 'text-success-fg'}>{e.amount > 0 ? '+' : ''}{fmt(e.amount)}</span>
            </li>))}</ul>}
        </ResourceView>
      </Card>
      {apply && <ApplyDialog onClose={() => setApply(false)} onSaved={refresh} />}
    </div>
  );
}

function RequestsCard({ scope, title, version = 0, onChanged }: { scope: RequestScope; title: string; version?: number; onChanged?: () => void }) {
  const { tt } = useTimeT();
  const source = useTimeSource();
  const { toast } = useToast();
  const [page, setPage] = useState(1);
  const [decision, setDecision] = useState<{ item: LeaveRequestItem; approve: boolean } | null>(null);
  const [cancelling, setCancelling] = useState<LeaveRequestItem | null>(null);
  const requests = useResource(useCallback((signal: AbortSignal) => { void version; return source.leaveRequests(scope, page, signal); }, [source, scope, page, version]));
  const changed = () => { requests.reload(); onChanged?.(); };
  const columns: DataTableColumn<LeaveRequestItem>[] = [
    ...(scope !== 'mine' ? [{ id: 'employee', header: tt('employee'), primary: true, cell: (r: LeaveRequestItem) => r.employee.name }] : []),
    { id: 'type', header: tt('leaveType'), primary: scope === 'mine', cell: (r) => r.leave_type.name },
    { id: 'dates', header: tt('dates'), cell: (r) => `${r.start_date}${r.end_date !== r.start_date ? ` – ${r.end_date}` : ''}${r.half_day ? ` (${tt(`halfDay.${r.half_day}`)})` : ''}` },
    { id: 'days', header: tt('days'), cell: (r) => fmt(r.days) },
    { id: 'status', header: tt('statusLabel'), cell: (r) => <div className="flex flex-col gap-1"><RequestStatusBadge status={r.status} />
      {r.status === 'pending' && r.approval_steps > 1 && <span className="text-xs text-fg-muted">{tt('stepOf', { step: r.current_step, total: r.approval_steps })}</span>}</div> },
    { id: 'actions', header: tt('actions'), cell: (r) => (
      <div className="flex flex-wrap gap-2">
        {r.can_decide && <><Button size="sm" onClick={() => setDecision({ item: r, approve: true })}>{tt('approve')}</Button>
          <Button size="sm" variant="secondary" onClick={() => setDecision({ item: r, approve: false })}>{tt('reject')}</Button></>}
        {r.can_cancel && scope !== 'approvals' && <Button size="sm" variant="tertiary" onClick={() => setCancelling(r)}>{tt('cancelRequest')}</Button>}
      </div>
    ) },
  ];
  return (
    <Card padding="none" title={title} headingLevel={2}>
      {requests.error instanceof ApiNotConnectedError ? <NotConnected /> : <>
        <DataTable caption={title} hideCaption columns={columns} rows={requests.data?.data ?? []} getRowId={(r) => r.id}
          loading={requests.loading} error={requests.error ? errorMessage(requests.error, tt('loadError')) : null} onRetry={requests.reload}
          empty={<EmptyState icon={<CalendarIcon size={22} />} title={tt('noLeaveRequests')} />} />
        <Pager meta={requests.data?.meta} onPage={setPage} />
      </>}
      {decision && <DecisionDialog approve={decision.approve} subject={`${decision.item.employee.name} · ${decision.item.leave_type.name} · ${fmt(decision.item.days)} ${tt('days')}`}
        onClose={() => setDecision(null)}
        onDecide={async (note) => { await source.decideLeave(decision.item.id, decision.approve, note); toast({ title: tt(decision.approve ? 'approved' : 'rejected'), tone: 'success' }); changed(); }} />}
      {cancelling && <CancelDialog item={cancelling} onClose={() => setCancelling(null)} onDone={changed} />}
    </Card>
  );
}

function CancelDialog({ item, onClose, onDone }: { item: LeaveRequestItem; onClose: () => void; onDone: () => void }) {
  const { tt, t } = useTimeT();
  const source = useTimeSource();
  const { toast } = useToast();
  const [reason, setReason] = useState('');
  const [error, setError] = useState<unknown>(null);
  const [busy, setBusy] = useState(false);
  const submit = async () => {
    setBusy(true);
    try {
      await source.cancelLeave(item.id, reason.trim() || null);
      toast({ title: tt('requestCancelled'), tone: 'success' });
      onDone();
      onClose();
    } catch (e) {
      setError(e);
    } finally {
      setBusy(false);
    }
  };
  return (
    <Modal open onClose={onClose} size="sm" title={tt('cancelLeaveTitle')} description={`${item.leave_type.name} · ${item.start_date} – ${item.end_date}`}
      footer={<><Button variant="secondary" onClick={onClose}>{t('ui.cancel')}</Button><Button variant="danger" loading={busy} onClick={submit}>{tt('cancelRequest')}</Button></>}>
      <div className="grid gap-3">
        {item.status === 'approved' && <p className="text-sm text-fg-muted">{tt('cancelReturnsDays', { days: fmt(item.days) })}</p>}
        <TextArea label={tt('reasonOptional')} value={reason} onChange={setReason} />
        <FormError message={error ? errorMessage(error, tt('actionFailed')) : null} />
      </div>
    </Modal>
  );
}

function ApplyDialog({ onClose, onSaved }: { onClose: () => void; onSaved: () => void }) {
  const { tt, t } = useTimeT();
  const source = useTimeSource();
  const { toast } = useToast();
  const types = useResource(useCallback((signal: AbortSignal) => source.leaveTypes(signal), [source]));
  const [f, setF] = useState({ type: '', start: '', end: '', half: '', reason: '' });
  const [preview, setPreview] = useState<{ key: string; days: number } | null>(null);
  const [error, setError] = useState<unknown>(null);
  const [busy, setBusy] = useState(false);
  const type = types.data?.find((x) => x.id === f.type);
  const single = f.start !== '' && f.start === f.end;
  const previewKey = `${f.start}|${f.end}|${single ? f.half : ''}`;
  useEffect(() => {
    if (!f.start || !f.end || f.end < f.start) return;
    let live = true;
    source.previewLeave(f.start, f.end, single && f.half ? f.half : null).then((d) => { if (live) setPreview({ key: previewKey, days: d }); }, () => undefined);
    return () => { live = false; };
  }, [source, f.start, f.end, f.half, single, previewKey]);
  const days = preview?.key === previewKey ? preview.days : null;
  const fields = validationErrors(error);
  const submit = async () => {
    setBusy(true);
    setError(null);
    try {
      await source.applyLeave({ leave_type: f.type, start_date: f.start, end_date: f.end, half_day: single && f.half ? f.half : null, reason: f.reason.trim() || null });
      toast({ title: tt('leaveSubmitted'), tone: 'success' });
      onSaved();
      onClose();
    } catch (e) {
      setError(e);
    } finally {
      setBusy(false);
    }
  };
  return (
    <Modal open onClose={onClose} title={tt('applyLeave')}
      footer={<><Button variant="secondary" onClick={onClose}>{t('ui.cancel')}</Button><Button loading={busy} disabled={!f.type || !f.start || !f.end} onClick={submit}>{tt('submit')}</Button></>}>
      <div className="grid gap-4 sm:grid-cols-2">
        <Select label={tt('leaveType')} required placeholder={tt('chooseType')} value={f.type} onChange={(e) => setF({ ...f, type: e.target.value, half: '' })}
          options={(types.data ?? []).map((x) => ({ value: x.id, label: x.name }))} error={fields.leave_type ?? fields.leave_type_id} />
        <div />
        <DatePicker label={tt('startDate')} required value={f.start} onChange={(v) => setF({ ...f, start: v, end: f.end && f.end >= v ? f.end : v })} error={fields.start_date} />
        <DatePicker label={tt('endDate')} required value={f.end} min={f.start} onChange={(v) => setF({ ...f, end: v })} error={fields.end_date} />
        {type?.allow_half_day && single && (
          <Select label={tt('halfDayLabel')} placeholder={tt('fullDay')} value={f.half} onChange={(e) => setF({ ...f, half: e.target.value })}
            options={[{ value: 'first_half', label: tt('halfDay.first_half') }, { value: 'second_half', label: tt('halfDay.second_half') }]} error={fields.half_day} />
        )}
        <p role="status" className="self-end text-sm text-fg-muted sm:col-span-2">{days !== null ? tt('workingDaysPreview', { days: fmt(days) }) : ''}</p>
        {type?.requires_document && <p className="text-sm text-fg-muted sm:col-span-2">{tt('documentRequired')}</p>}
        <TextArea label={tt('reasonOptional')} value={f.reason} onChange={(v) => setF({ ...f, reason: v })} error={fields.reason} />
        <FormError message={formError(error, tt('actionFailed'))} />
      </div>
    </Modal>
  );
}

function Holidays() {
  const { tt } = useTimeT();
  const source = useTimeSource();
  const [year, setYear] = useState(thisYear);
  const holidays = useResource(useCallback((signal: AbortSignal) => source.holidays(year, signal), [source, year]));
  return (
    <Card title={tt('holidayCalendar')} headingLevel={2} description={holidays.data?.meta.work_location ?? undefined}
      actions={<Select label={tt('year')} hideLabel value={String(year)} onChange={(e) => setYear(Number(e.target.value))} options={[thisYear - 1, thisYear, thisYear + 1].map((y) => ({ value: String(y), label: String(y) }))} />}>
      <ResourceView resource={holidays} empty={tt('noHolidays')} isEmpty={(d) => d.data.length === 0}>
        {(d) => <ul className="divide-y divide-line text-sm">{d.data.map((h) => (
          <li key={`${h.date}-${h.name}`} className="flex flex-wrap justify-between gap-2 py-2"><span><span className="font-medium text-fg">{h.date}</span> · {h.name}</span>
            {h.paid === false && <Badge size="sm">{tt('unpaid')}</Badge>}</li>))}</ul>}
      </ResourceView>
    </Card>
  );
}

function LeaveSettings() {
  const { tt } = useTimeT();
  const source = useTimeSource();
  const { toast } = useToast();
  const types = useResource(useCallback((signal: AbortSignal) => source.leaveTypes(signal), [source]));
  const [editing, setEditing] = useState<LeaveType | 'new' | null>(null);
  const [selected, setSelected] = useState<string | null>(null);
  const [year, setYear] = useState(thisYear);
  const run = (fn: () => Promise<unknown>, label: (r: never) => string) => fn().then(
    (r) => toast({ title: label(r as never), tone: 'success' }),
    (e: unknown) => toast({ title: errorMessage(e, tt('actionFailed')), tone: 'danger' }),
  );
  const current = types.data?.find((x) => x.id === selected) ?? null;
  return (
    <div className="space-y-6">
      <Card title={tt('leaveTypes')} headingLevel={2} description={tt('leaveTypesHint')} actions={<Button size="sm" onClick={() => setEditing('new')}>{tt('addLeaveType')}</Button>}>
        <ResourceView resource={types} empty={tt('noLeaveTypes')} isEmpty={(d) => d.length === 0}>
          {(list) => <ul className="divide-y divide-line">{list.map((lt) => (
            <li key={lt.id} className="flex flex-wrap items-center justify-between gap-2 py-3 text-sm">
              <div><span className="font-medium text-fg">{lt.name}</span> <span className="text-fg-muted">({lt.code}){lt.country_code ? ` · ${lt.country_code}` : ''} · {tt('stepsCount', { count: lt.approval_steps })}</span>
                {lt.status === 'inactive' && <> <Badge size="sm">{tt('inactive')}</Badge></>}</div>
              <div className="flex gap-2">
                <Button size="sm" variant={selected === lt.id ? 'primary' : 'secondary'} onClick={() => setSelected(lt.id)}>{tt('policies')}</Button>
                <Button size="sm" variant="tertiary" onClick={() => setEditing(lt)}>{tt('edit')}</Button>
              </div>
            </li>))}</ul>}
        </ResourceView>
      </Card>
      {current && <>
        <PoliciesCard type={current} />
        <Card title={tt('yearEnd', { name: current.name })} headingLevel={2} description={tt('yearEndHint')}>
          <div className="flex flex-wrap items-end gap-3">
            <Select label={tt('year')} value={String(year)} onChange={(e) => setYear(Number(e.target.value))} options={[thisYear - 1, thisYear, thisYear + 1].map((y) => ({ value: String(y), label: String(y) }))} />
            <Button variant="secondary" onClick={() => run(() => source.allocate(current.id, year), (r: { entries_created: number }) => tt('allocated', { count: r.entries_created }))}>{tt('allocate')}</Button>
            <Button variant="secondary" disabled={year >= thisYear} onClick={() => run(() => source.carryForward(current.id, year), (r: { employees: number }) => tt('carriedForwardDone', { count: r.employees }))}>{tt('runCarryForward')}</Button>
          </div>
        </Card>
      </>}
      {editing && <LeaveTypeDialog type={editing === 'new' ? null : editing} onClose={() => setEditing(null)} onSaved={types.reload} />}
    </div>
  );
}

function PoliciesCard({ type }: { type: LeaveType }) {
  const { tt, t } = useTimeT();
  const source = useTimeSource();
  const { toast } = useToast();
  const policies = useResource(useCallback((signal: AbortSignal) => source.policies(type.id, signal), [source, type.id]));
  const [open, setOpen] = useState(false);
  const [f, setF] = useState<LeavePolicyInput>({ effective_from: `${thisYear}-01-01`, annual_entitlement: 0, accrual: 'annual', prorate_new_joiners: true, carry_forward_max: 0, carry_forward_expiry_months: null, allow_negative: false, max_consecutive_days: null, min_notice_days: 0 });
  const [error, setError] = useState<unknown>(null);
  const [busy, setBusy] = useState(false);
  const fields = validationErrors(error);
  const num = (v: string) => (v === '' ? null : Number(v));
  const save = async () => {
    setBusy(true);
    setError(null);
    try {
      await source.savePolicy(type.id, f);
      toast({ title: tt('saved'), tone: 'success' });
      setOpen(false);
      policies.reload();
    } catch (e) {
      setError(e);
    } finally {
      setBusy(false);
    }
  };
  return (
    <Card title={tt('policiesFor', { name: type.name })} headingLevel={2} description={tt('policiesHint')} actions={<Button size="sm" onClick={() => setOpen(true)}>{tt('addPolicy')}</Button>}>
      <ResourceView resource={policies} empty={tt('noPolicies')} isEmpty={(d) => d.length === 0}>
        {(list) => <ul className="divide-y divide-line text-sm">{list.map((p) => (
          <li key={p.id} className="py-3">
            <div className="font-medium text-fg">{p.effective_from} – {p.effective_to ?? tt('openEnded')}{p.legal_entity ? ` · ${p.legal_entity.name}` : ''}</div>
            <div className="text-fg-muted">{tt('policySummary', { days: fmt(p.annual_entitlement), accrual: tt(`accrual.${p.accrual}`), carry: fmt(p.carry_forward_max) })}
              {p.carry_forward_expiry_months ? ` · ${tt('expiresAfter', { months: p.carry_forward_expiry_months })}` : ''}{p.allow_negative ? ` · ${tt('negativeAllowed')}` : ''}</div>
          </li>))}</ul>}
      </ResourceView>
      {open && (
        <Modal open onClose={() => setOpen(false)} size="lg" title={tt('addPolicy')} description={tt('newPolicyHint')}
          footer={<><Button variant="secondary" onClick={() => setOpen(false)}>{t('ui.cancel')}</Button><Button loading={busy} onClick={save}>{tt('save')}</Button></>}>
          <div className="grid gap-4 sm:grid-cols-2">
            <DatePicker label={tt('effectiveFrom')} required value={f.effective_from ?? ''} onChange={(v) => setF({ ...f, effective_from: v })} error={fields.effective_from} />
            <DatePicker label={tt('effectiveTo')} value={f.effective_to ?? ''} min={f.effective_from} onChange={(v) => setF({ ...f, effective_to: v || null })} error={fields.effective_to} />
            <Input label={tt('annualEntitlement')} required type="number" min={0} step={0.25} value={String(f.annual_entitlement ?? 0)} onChange={(e) => setF({ ...f, annual_entitlement: Number(e.target.value) })} error={fields.annual_entitlement} />
            <Select label={tt('accrualLabel')} value={f.accrual ?? 'annual'} onChange={(e) => setF({ ...f, accrual: e.target.value as 'annual' })}
              options={(['annual', 'monthly', 'none'] as const).map((a) => ({ value: a, label: tt(`accrual.${a}`) }))} error={fields.accrual} />
            <Input label={tt('carryForwardMax')} type="number" min={0} step={0.25} value={String(f.carry_forward_max ?? 0)} onChange={(e) => setF({ ...f, carry_forward_max: Number(e.target.value) })} error={fields.carry_forward_max} />
            <Input label={tt('carryForwardExpiry')} hint={tt('carryForwardExpiryHint')} type="number" min={1} max={24} value={f.carry_forward_expiry_months == null ? '' : String(f.carry_forward_expiry_months)} onChange={(e) => setF({ ...f, carry_forward_expiry_months: num(e.target.value) })} error={fields.carry_forward_expiry_months} />
            <Input label={tt('maxConsecutive')} type="number" min={1} value={f.max_consecutive_days == null ? '' : String(f.max_consecutive_days)} onChange={(e) => setF({ ...f, max_consecutive_days: num(e.target.value) })} error={fields.max_consecutive_days} />
            <Input label={tt('minNotice')} type="number" min={0} value={String(f.min_notice_days ?? 0)} onChange={(e) => setF({ ...f, min_notice_days: Number(e.target.value) })} error={fields.min_notice_days} />
            <Checkbox label={tt('prorate')} checked={f.prorate_new_joiners ?? false} onChange={(e) => setF({ ...f, prorate_new_joiners: e.target.checked })} />
            <Checkbox label={tt('allowNegative')} checked={f.allow_negative ?? false} onChange={(e) => setF({ ...f, allow_negative: e.target.checked })} />
            <FormError message={formError(error, tt('actionFailed'))} />
          </div>
        </Modal>
      )}
    </Card>
  );
}

function LeaveTypeDialog({ type, onClose, onSaved }: { type: LeaveType | null; onClose: () => void; onSaved: () => void }) {
  const { tt, t } = useTimeT();
  const source = useTimeSource();
  const { toast } = useToast();
  const [f, setF] = useState<LeaveTypeInput>(type ?? { name: '', code: '', country_code: null, rule_key: null, is_paid: true, allow_half_day: false, requires_document: false, approval_steps: 1, status: 'active' });
  const [error, setError] = useState<unknown>(null);
  const [busy, setBusy] = useState(false);
  const fields = validationErrors(error);
  const save = async () => {
    setBusy(true);
    setError(null);
    try {
      await source.saveLeaveType({ ...f, country_code: f.country_code || null, rule_key: f.rule_key || null }, type?.id);
      toast({ title: tt('saved'), tone: 'success' });
      onSaved();
      onClose();
    } catch (e) {
      setError(e);
    } finally {
      setBusy(false);
    }
  };
  return (
    <Modal open onClose={onClose} size="lg" title={tt(type ? 'editLeaveType' : 'addLeaveType')}
      footer={<><Button variant="secondary" onClick={onClose}>{t('ui.cancel')}</Button><Button loading={busy} onClick={save}>{tt('save')}</Button></>}>
      <div className="grid gap-4 sm:grid-cols-2">
        <Input label={tt('name')} required value={f.name ?? ''} onChange={(e) => setF({ ...f, name: e.target.value })} error={fields.name} />
        <Input label={tt('code')} required hint={tt('codeHint')} value={f.code ?? ''} onChange={(e) => setF({ ...f, code: e.target.value })} error={fields.code} />
        <Select label={tt('country')} placeholder={tt('allCountries')} value={f.country_code ?? ''} onChange={(e) => setF({ ...f, country_code: e.target.value || null })}
          options={['US', 'AE', 'CA', 'MX'].map((c) => ({ value: c, label: c }))} error={fields.country_code} />
        <Input label={tt('ruleKey')} hint={tt('ruleKeyHint')} value={f.rule_key ?? ''} disabled={!f.country_code} onChange={(e) => setF({ ...f, rule_key: e.target.value })} error={fields.rule_key} />
        <Select label={tt('approvalSteps')} value={String(f.approval_steps ?? 1)} onChange={(e) => setF({ ...f, approval_steps: Number(e.target.value) as 1 | 2 })}
          options={[{ value: '1', label: tt('oneStep') }, { value: '2', label: tt('twoSteps') }]} error={fields.approval_steps} />
        <Select label={tt('statusLabel')} value={f.status ?? 'active'} onChange={(e) => setF({ ...f, status: e.target.value as 'active' })}
          options={[{ value: 'active', label: tt('active') }, { value: 'inactive', label: tt('inactive') }]} />
        <Checkbox label={tt('paid')} checked={f.is_paid ?? true} onChange={(e) => setF({ ...f, is_paid: e.target.checked })} />
        <Checkbox label={tt('allowHalfDay')} checked={f.allow_half_day ?? false} onChange={(e) => setF({ ...f, allow_half_day: e.target.checked })} />
        <Checkbox label={tt('requiresDocument')} checked={f.requires_document ?? false} onChange={(e) => setF({ ...f, requires_document: e.target.checked })} />
        <FormError message={formError(error, tt('actionFailed'))} />
      </div>
    </Modal>
  );
}
