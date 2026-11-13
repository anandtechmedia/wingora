import { useCallback, useMemo, useState } from 'react';
import { Permission as P } from '@wingora/permissions';
import { ApiNotConnectedError } from '@/api/dashboard';
import { useTimeSource, type AttendanceRecord, type AttendanceRequestItem, type RangeQuery, type ReportRow, type Shift, type ShiftInput, type WorkSchedule } from '@/api/time';
import { Badge, Button, Card, Checkbox, ClockIcon, DataTable, DatePicker, EmptyState, Input, Modal, Pagination, Select, Tabs, useToast, type DataTableColumn } from '@/components/ui';
import { useSession } from '@/auth/session';
import { PageHeader } from '@/shell/page-header';
import { errorMessage, FormError, formError, ResourceView, useResource, validationErrors } from '@/modules/employees/shared';
import { daysAgo, DecisionDialog, formatMinutes, isoDate, RequestStatusBadge, TextArea, useTimeT } from '@/modules/time/shared';

export default function Attendance() {
  const { tt, t } = useTimeT();
  const { permissions } = useSession();
  const can = (...p: string[]) => p.some((x) => permissions.has(x));
  const tabs = [
    can(P.AttendanceView) && { id: 'mine', label: tt('myAttendance'), content: <MyAttendance /> },
    can(P.AttendanceApprove, P.AttendanceManage) && { id: 'approvals', label: tt('approvals'), content: <AttendanceApprovals /> },
    can(P.AttendanceApprove, P.AttendanceManage) && { id: 'report', label: tt('report'), content: <AttendanceReport /> },
    can(P.AttendanceConfigure) && { id: 'settings', label: tt('shiftsAndSchedules'), content: <ShiftSettings /> },
  ].filter((x): x is { id: string; label: string; content: React.ReactElement } => Boolean(x));
  return (
    <>
      <PageHeader title={t('nav.module.attendance')} description={tt('attendanceSubtitle')} />
      {tabs.length > 0 ? <Tabs label={t('nav.module.attendance')} tabs={tabs} /> : <EmptyState title={tt('nothingToShow')} />}
    </>
  );
}

function ClockCard({ onChanged }: { onChanged: () => void }) {
  const { tt } = useTimeT();
  const source = useTimeSource();
  const { toast } = useToast();
  const today = useResource(useCallback((signal: AbortSignal) => source.today(signal), [source]));
  const [busy, setBusy] = useState(false);
  const act = async (fn: () => Promise<unknown>, done: string) => {
    setBusy(true);
    try {
      await fn();
      toast({ title: done, tone: 'success' });
    } catch (e) {
      toast({ title: errorMessage(e, tt('actionFailed')), tone: 'danger' });
    } finally {
      setBusy(false);
      today.reload();
      onChanged();
    }
  };
  return (
    <Card title={tt('today')} headingLevel={2}>
      <ResourceView resource={today} empty="">
        {(d) => {
          const open = d.record?.open ?? false;
          const done = d.record && !open;
          return (
            <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
              <div className="space-y-1 text-sm">
                <p className="text-base font-semibold text-fg">{d.today}</p>
                <p className="text-fg-muted">{tt('timezone')}: {d.timezone}</p>
                <p className="text-fg-muted">{d.shift ? tt('shiftHours', { name: d.shift.name, start: d.shift.start_time, end: d.shift.end_time }) : tt('noShift')}</p>
                {d.record && <p role="status" className="text-fg">
                  {open ? tt('clockedInAt', { time: d.record.clock_in_local.slice(11) }) : tt('clockedInOut', { in: d.record.clock_in_local.slice(11), out: d.record.clock_out_local?.slice(11) ?? '' })}
                  {d.record.late_minutes > 0 && <> · <Badge tone="warning" size="sm">{tt('lateBy', { time: formatMinutes(d.record.late_minutes) })}</Badge></>}
                </p>}
              </div>
              {d.can_clock && !done && (open
                ? <Button size="lg" variant="secondary" loading={busy} leadingIcon={<ClockIcon size={18} />} onClick={() => act(() => source.clockOut(), tt('clockedOut'))}>{tt('clockOut')}</Button>
                : <Button size="lg" loading={busy} leadingIcon={<ClockIcon size={18} />} onClick={() => act(() => source.clockIn(), tt('clockedIn'))}>{tt('clockIn')}</Button>)}
            </div>
          );
        }}
      </ResourceView>
    </Card>
  );
}

function useRecordColumns(showEmployee: boolean) {
  const { tt } = useTimeT();
  return useMemo<DataTableColumn<AttendanceRecord>[]>(() => [
    ...(showEmployee ? [{ id: 'employee', header: tt('employee'), primary: true, cell: (r: AttendanceRecord) => r.employee.name }] : []),
    { id: 'date', header: tt('date'), primary: !showEmployee, cell: (r) => r.work_date },
    { id: 'in', header: tt('in'), cell: (r) => r.clock_in_local.slice(11) },
    { id: 'out', header: tt('out'), cell: (r) => r.clock_out_local ? (r.clock_out_local.slice(0, 10) === r.work_date ? r.clock_out_local.slice(11) : r.clock_out_local) : <Badge tone="info" size="sm">{tt('open')}</Badge> },
    { id: 'worked', header: tt('worked'), cell: (r) => formatMinutes(r.worked_minutes) },
    { id: 'late', header: tt('late'), hideBelow: 'md', cell: (r) => r.late_minutes ? formatMinutes(r.late_minutes) : '—' },
    { id: 'early', header: tt('early'), hideBelow: 'md', cell: (r) => r.early_minutes ? formatMinutes(r.early_minutes) : '—' },
    { id: 'overtime', header: tt('overtime'), hideBelow: 'md', cell: (r) => r.overtime_minutes ? formatMinutes(r.overtime_minutes) : '—' },
    { id: 'source', header: tt('source'), hideBelow: 'lg', cell: (r) => tt(`source.${r.source}`) },
  ], [tt, showEmployee]);
}

function MyAttendance() {
  const { tt } = useTimeT();
  const source = useTimeSource();
  const { permissions } = useSession();
  const [page, setPage] = useState(1);
  const [requestPage, setRequestPage] = useState(1);
  const [dialog, setDialog] = useState(false);
  const records = useResource(useCallback((signal: AbortSignal) => source.records({ from: daysAgo(30), to: isoDate(new Date()), page }, signal), [source, page]));
  const requests = useResource(useCallback((signal: AbortSignal) => source.attendanceRequests('mine', requestPage, signal), [source, requestPage]));
  const columns = useRecordColumns(false);
  return (
    <div className="space-y-6">
      <ClockCard onChanged={records.reload} />
      <Card padding="none" title={tt('last30Days')} headingLevel={2}>
        <RecordTable resource={records} columns={columns} onPage={setPage} />
      </Card>
      <Card padding="none" title={tt('correctionRequests')} headingLevel={2}
        actions={permissions.has(P.AttendanceClock) && <Button size="sm" variant="secondary" onClick={() => setDialog(true)}>{tt('requestCorrection')}</Button>}>
        <RequestTable resource={requests} onPage={setRequestPage} onChanged={requests.reload} />
      </Card>
      {dialog && <CorrectionDialog onClose={() => setDialog(false)} onSaved={requests.reload} />}
    </div>
  );
}

function RecordTable({ resource, columns, onPage }: { resource: ReturnType<typeof useResource<import('@/api/employees').Page<AttendanceRecord>>>; columns: DataTableColumn<AttendanceRecord>[]; onPage: (p: number) => void }) {
  const { tt } = useTimeT();
  if (resource.error instanceof ApiNotConnectedError) return <NotConnected />;
  return (
    <>
      <DataTable caption={tt('records')} hideCaption columns={columns} rows={resource.data?.data ?? []} getRowId={(r) => r.id}
        loading={resource.loading} error={resource.error ? errorMessage(resource.error, tt('loadError')) : null} onRetry={resource.reload}
        empty={<EmptyState icon={<ClockIcon size={22} />} title={tt('noRecords')} />} />
      <Pager meta={resource.data?.meta} onPage={onPage} />
    </>
  );
}

export function Pager({ meta, onPage }: { meta?: { current_page: number; last_page: number; per_page: number; total: number }; onPage: (p: number) => void }) {
  if (!meta || meta.last_page <= 1) return null;
  return <div className="border-t border-line p-3"><Pagination page={meta.current_page} pageCount={meta.last_page} total={meta.total} pageSize={meta.per_page} onPageChange={onPage} /></div>;
}

export function NotConnected() {
  const { tt } = useTimeT();
  return <EmptyState title={tt('notConnectedTitle')} description={tt('notConnectedDescription')} />;
}

function RequestTable({ resource, onPage, onChanged, showEmployee = false }: {
  resource: ReturnType<typeof useResource<import('@/api/employees').Page<AttendanceRequestItem>>>; onPage: (p: number) => void; onChanged: () => void; showEmployee?: boolean;
}) {
  const { tt } = useTimeT();
  const source = useTimeSource();
  const { toast } = useToast();
  const [decision, setDecision] = useState<{ item: AttendanceRequestItem; approve: boolean } | null>(null);
  const cancel = (item: AttendanceRequestItem) => source.cancelAttendance(item.id).then(
    () => { toast({ title: tt('requestCancelled'), tone: 'success' }); onChanged(); },
    (e: unknown) => toast({ title: errorMessage(e, tt('actionFailed')), tone: 'danger' }),
  );
  const columns: DataTableColumn<AttendanceRequestItem>[] = [
    ...(showEmployee ? [{ id: 'employee', header: tt('employee'), primary: true, cell: (r: AttendanceRequestItem) => r.employee.name }] : []),
    { id: 'date', header: tt('date'), primary: !showEmployee, cell: (r) => r.work_date },
    { id: 'times', header: tt('requestedTimes'), cell: (r) => `${r.clock_in_local.slice(11)} – ${r.clock_out_local?.slice(11) ?? '…'}` },
    { id: 'reason', header: tt('reason'), hideBelow: 'md', cell: (r) => <span className="line-clamp-2">{r.reason}</span> },
    { id: 'status', header: tt('statusLabel'), cell: (r) => <RequestStatusBadge status={r.status} /> },
    { id: 'actions', header: tt('actions'), cell: (r) => (
      <div className="flex flex-wrap gap-2">
        {r.can_decide && <><Button size="sm" onClick={() => setDecision({ item: r, approve: true })}>{tt('approve')}</Button>
          <Button size="sm" variant="secondary" onClick={() => setDecision({ item: r, approve: false })}>{tt('reject')}</Button></>}
        {r.can_cancel && !r.can_decide && <Button size="sm" variant="tertiary" onClick={() => cancel(r)}>{tt('cancelRequest')}</Button>}
      </div>
    ) },
  ];
  if (resource.error instanceof ApiNotConnectedError) return <NotConnected />;
  return (
    <>
      <DataTable caption={tt('correctionRequests')} hideCaption columns={columns} rows={resource.data?.data ?? []} getRowId={(r) => r.id}
        loading={resource.loading} error={resource.error ? errorMessage(resource.error, tt('loadError')) : null} onRetry={resource.reload}
        empty={<EmptyState title={tt('noRequests')} />} />
      <Pager meta={resource.data?.meta} onPage={onPage} />
      {decision && <DecisionDialog approve={decision.approve} subject={`${decision.item.employee.name} · ${decision.item.work_date}`} onClose={() => setDecision(null)}
        onDecide={async (note) => { await source.decideAttendance(decision.item.id, decision.approve, note); toast({ title: tt(decision.approve ? 'approved' : 'rejected'), tone: 'success' }); onChanged(); }} />}
    </>
  );
}

const TIME = /^([01]\d|2[0-3]):[0-5]\d$/;
function CorrectionDialog({ onClose, onSaved }: { onClose: () => void; onSaved: () => void }) {
  const { tt, t } = useTimeT();
  const source = useTimeSource();
  const { toast } = useToast();
  const [form, setForm] = useState({ date: daysAgo(1), in: '09:00', outDate: '', out: '', reason: '' });
  const [error, setError] = useState<unknown>(null);
  const [busy, setBusy] = useState(false);
  const set = (patch: Partial<typeof form>) => setForm((f) => ({ ...f, ...patch }));
  const fields = validationErrors(error);
  const submit = async () => {
    setBusy(true);
    setError(null);
    try {
      await source.submitCorrection({ work_date: form.date, clock_in: `${form.date} ${form.in}`, clock_out: form.out ? `${form.outDate || form.date} ${form.out}` : null, reason: form.reason.trim() });
      toast({ title: tt('requestSubmitted'), tone: 'success' });
      onSaved();
      onClose();
    } catch (e) {
      setError(e);
    } finally {
      setBusy(false);
    }
  };
  const invalid = !form.date || !TIME.test(form.in) || (form.out !== '' && !TIME.test(form.out)) || form.reason.trim().length < 5;
  return (
    <Modal open onClose={onClose} title={tt('requestCorrection')} description={tt('correctionHint')}
      footer={<><Button variant="secondary" onClick={onClose}>{t('ui.cancel')}</Button><Button loading={busy} disabled={invalid} onClick={submit}>{tt('submit')}</Button></>}>
      <div className="grid gap-4 sm:grid-cols-2">
        <DatePicker label={tt('workDate')} required value={form.date} max={isoDate(new Date())} onChange={(v) => set({ date: v })} error={fields.work_date} />
        <Input label={tt('clockInTime')} type="time" required value={form.in} onChange={(e) => set({ in: e.target.value })} error={fields.clock_in} />
        <DatePicker label={tt('clockOutDate')} hint={tt('clockOutDateHint')} value={form.outDate} min={form.date} onChange={(v) => set({ outDate: v })} />
        <Input label={tt('clockOutTime')} type="time" value={form.out} onChange={(e) => set({ out: e.target.value })} error={fields.clock_out} />
        <TextArea label={tt('reason')} required value={form.reason} onChange={(v) => set({ reason: v })} error={fields.reason} />
        <FormError message={formError(error, tt('actionFailed'))} />
      </div>
    </Modal>
  );
}

function AttendanceApprovals() {
  const source = useTimeSource();
  const { tt } = useTimeT();
  const [page, setPage] = useState(1);
  const requests = useResource(useCallback((signal: AbortSignal) => source.attendanceRequests('approvals', page, signal), [source, page]));
  return <Card padding="none" title={tt('pendingCorrections')} headingLevel={2}><RequestTable resource={requests} onPage={setPage} onChanged={requests.reload} showEmployee /></Card>;
}

function AttendanceReport() {
  const { tt } = useTimeT();
  const source = useTimeSource();
  const { toast } = useToast();
  const [query, setQuery] = useState<RangeQuery>({ from: daysAgo(29), to: isoDate(new Date()), page: 1, search: '' });
  const [search, setSearch] = useState('');
  const report = useResource(useCallback((signal: AbortSignal) => source.report(query, signal), [source, query]));
  const columns: DataTableColumn<ReportRow>[] = [
    { id: 'employee', header: tt('employee'), primary: true, cell: (r) => <div><div className="font-medium">{r.employee.name}</div><div className="text-xs text-fg-muted">{r.employee.employee_number}{r.department ? ` · ${r.department}` : ''}</div></div> },
    { id: 'present', header: tt('present'), cell: (r) => r.days_present },
    { id: 'absent', header: tt('absent'), cell: (r) => r.days_absent },
    { id: 'late', header: tt('late'), hideBelow: 'md', cell: (r) => `${r.late_count} · ${formatMinutes(r.late_minutes)}` },
    { id: 'early', header: tt('early'), hideBelow: 'md', cell: (r) => `${r.early_count} · ${formatMinutes(r.early_minutes)}` },
    { id: 'worked', header: tt('worked'), hideBelow: 'lg', cell: (r) => formatMinutes(r.worked_minutes) },
    { id: 'overtime', header: tt('overtime'), cell: (r) => formatMinutes(r.overtime_minutes) },
  ];
  const update = (patch: Partial<RangeQuery>) => setQuery((q) => ({ ...q, page: 1, ...patch }));
  return (
    <Card padding="none">
      <form role="search" aria-label={tt('reportFilters')} className="grid gap-3 border-b border-line p-4 sm:grid-cols-2 lg:grid-cols-4"
        onSubmit={(e) => { e.preventDefault(); update({ search: search.trim() }); }}>
        <DatePicker label={tt('from')} value={query.from} max={query.to} onChange={(v) => v && update({ from: v })} />
        <DatePicker label={tt('to')} value={query.to} min={query.from} onChange={(v) => v && update({ to: v })} />
        <Input label={tt('searchEmployees')} type="search" value={search} onChange={(e) => setSearch(e.target.value)} onBlur={() => update({ search: search.trim() })} />
        <div className="flex items-end"><Button variant="secondary" onClick={() => source.exportReport(query).catch((e: unknown) => toast({ title: errorMessage(e, tt('exportFailed')), tone: 'danger' }))}>{tt('exportCsv')}</Button></div>
      </form>
      {report.error instanceof ApiNotConnectedError ? <NotConnected /> : <>
        <DataTable caption={tt('report')} hideCaption columns={columns} rows={report.data?.data ?? []} getRowId={(r) => r.employee.id}
          loading={report.loading} error={report.error ? errorMessage(report.error, tt('loadError')) : null} onRetry={report.reload}
          empty={<EmptyState title={tt('noReportRows')} />} />
        <Pager meta={report.data?.meta} onPage={(page) => setQuery((q) => ({ ...q, page }))} />
      </>}
    </Card>
  );
}

const WEEKDAYS = ['1', '2', '3', '4', '5', '6', '7'] as const;
function ShiftSettings() {
  const { tt } = useTimeT();
  const source = useTimeSource();
  const shifts = useResource(useCallback((signal: AbortSignal) => source.shifts(signal), [source]));
  const schedules = useResource(useCallback((signal: AbortSignal) => source.schedules(signal), [source]));
  const [shift, setShift] = useState<Shift | 'new' | null>(null);
  const [schedule, setSchedule] = useState<WorkSchedule | 'new' | null>(null);
  return (
    <div className="space-y-6">
      <Card title={tt('shifts')} headingLevel={2} actions={<Button size="sm" onClick={() => setShift('new')}>{tt('addShift')}</Button>}>
        <ResourceView resource={shifts} empty={tt('noShifts')} isEmpty={(d) => d.length === 0}>
          {(list) => <ul className="divide-y divide-line">{list.map((s) => (
            <li key={s.id} className="flex flex-wrap items-center justify-between gap-2 py-3 text-sm">
              <div><span className="font-medium text-fg">{s.name}</span> <span className="text-fg-muted">({s.code}) · {s.start_time}–{s.end_time}{s.overnight ? ` · ${tt('overnight')}` : ''} · {tt('breakMinutes', { minutes: s.break_minutes })}</span>
                {s.status === 'inactive' && <> <Badge size="sm">{tt('inactive')}</Badge></>}</div>
              <Button size="sm" variant="tertiary" onClick={() => setShift(s)}>{tt('edit')}</Button>
            </li>))}</ul>}
        </ResourceView>
      </Card>
      <Card title={tt('schedules')} headingLevel={2} actions={<Button size="sm" onClick={() => setSchedule('new')} disabled={!shifts.data?.length}>{tt('addSchedule')}</Button>}>
        <ResourceView resource={schedules} empty={tt('noSchedules')} isEmpty={(d) => d.length === 0}>
          {(list) => <ul className="divide-y divide-line">{list.map((s) => (
            <li key={s.id} className="flex flex-wrap items-center justify-between gap-2 py-3 text-sm">
              <div className="min-w-0"><span className="font-medium text-fg">{s.name}</span>{s.is_default && <> <Badge tone="accent" size="sm">{tt('default')}</Badge></>}
                <div className="text-fg-muted">{WEEKDAYS.map((d) => `${tt(`weekday.${d}`)}: ${s.days[d]?.name ?? tt('rest')}`).join(' · ')}</div></div>
              <Button size="sm" variant="tertiary" onClick={() => setSchedule(s)}>{tt('edit')}</Button>
            </li>))}</ul>}
        </ResourceView>
      </Card>
      {shift && <ShiftDialog shift={shift === 'new' ? null : shift} onClose={() => setShift(null)} onSaved={() => { shifts.reload(); schedules.reload(); }} />}
      {schedule && <ScheduleDialog schedule={schedule === 'new' ? null : schedule} shifts={shifts.data ?? []} onClose={() => setSchedule(null)} onSaved={schedules.reload} />}
    </div>
  );
}

function useSave(onSaved: () => void, onClose: () => void) {
  const { tt } = useTimeT();
  const { toast } = useToast();
  const [error, setError] = useState<unknown>(null);
  const [busy, setBusy] = useState(false);
  const run = async (fn: () => Promise<unknown>) => {
    setBusy(true);
    setError(null);
    try {
      await fn();
      toast({ title: tt('saved'), tone: 'success' });
      onSaved();
      onClose();
    } catch (e) {
      setError(e);
    } finally {
      setBusy(false);
    }
  };
  return { error, busy, run, fields: validationErrors(error) };
}

function ShiftDialog({ shift, onClose, onSaved }: { shift: Shift | null; onClose: () => void; onSaved: () => void }) {
  const { tt, t } = useTimeT();
  const source = useTimeSource();
  const [f, setF] = useState<ShiftInput>(shift ?? { name: '', code: '', start_time: '09:00', end_time: '17:00', break_minutes: 60, late_grace_minutes: 5, early_grace_minutes: 5, overtime_after_minutes: null, status: 'active' });
  const { error, busy, run, fields } = useSave(onSaved, onClose);
  const num = (v: string) => (v === '' ? null : Number(v));
  return (
    <Modal open onClose={onClose} title={tt(shift ? 'editShift' : 'addShift')} description={tt('shiftHint')}
      footer={<><Button variant="secondary" onClick={onClose}>{t('ui.cancel')}</Button><Button loading={busy} onClick={() => run(() => source.saveShift(f, shift?.id))}>{tt('save')}</Button></>}>
      <div className="grid gap-4 sm:grid-cols-2">
        <Input label={tt('name')} required value={f.name ?? ''} onChange={(e) => setF({ ...f, name: e.target.value })} error={fields.name} />
        <Input label={tt('code')} required value={f.code ?? ''} onChange={(e) => setF({ ...f, code: e.target.value })} error={fields.code} />
        <Input label={tt('startTime')} type="time" required value={f.start_time ?? ''} onChange={(e) => setF({ ...f, start_time: e.target.value })} error={fields.start_time} />
        <Input label={tt('endTime')} type="time" required hint={tt('endTimeHint')} value={f.end_time ?? ''} onChange={(e) => setF({ ...f, end_time: e.target.value })} error={fields.end_time} />
        <Input label={tt('breakLabel')} type="number" min={0} max={240} value={String(f.break_minutes ?? 0)} onChange={(e) => setF({ ...f, break_minutes: Number(e.target.value) })} error={fields.break_minutes} />
        <Input label={tt('overtimeAfter')} hint={tt('overtimeAfterHint')} type="number" min={0} value={f.overtime_after_minutes == null ? '' : String(f.overtime_after_minutes)} onChange={(e) => setF({ ...f, overtime_after_minutes: num(e.target.value) })} error={fields.overtime_after_minutes} />
        <Input label={tt('lateGrace')} type="number" min={0} max={240} value={String(f.late_grace_minutes ?? 0)} onChange={(e) => setF({ ...f, late_grace_minutes: Number(e.target.value) })} error={fields.late_grace_minutes} />
        <Input label={tt('earlyGrace')} type="number" min={0} max={240} value={String(f.early_grace_minutes ?? 0)} onChange={(e) => setF({ ...f, early_grace_minutes: Number(e.target.value) })} error={fields.early_grace_minutes} />
        <Select label={tt('statusLabel')} value={f.status ?? 'active'} onChange={(e) => setF({ ...f, status: e.target.value as 'active' | 'inactive' })}
          options={[{ value: 'active', label: tt('active') }, { value: 'inactive', label: tt('inactive') }]} />
        <FormError message={formError(error, tt('actionFailed'))} />
      </div>
    </Modal>
  );
}

function ScheduleDialog({ schedule, shifts, onClose, onSaved }: { schedule: WorkSchedule | null; shifts: Shift[]; onClose: () => void; onSaved: () => void }) {
  const { tt, t } = useTimeT();
  const source = useTimeSource();
  const first = shifts.find((s) => s.status === 'active')?.id ?? null;
  const [name, setName] = useState(schedule?.name ?? '');
  const [isDefault, setDefault] = useState(schedule?.is_default ?? false);
  const [days, setDays] = useState<Record<string, string | null>>(() => Object.fromEntries(WEEKDAYS.map((d) => [d, schedule ? schedule.days[d]?.id ?? null : Number(d) <= 5 ? first : null])));
  const { error, busy, run, fields } = useSave(onSaved, onClose);
  const options = shifts.filter((s) => s.status === 'active').map((s) => ({ value: s.id, label: `${s.name} (${s.start_time}–${s.end_time})` }));
  return (
    <Modal open onClose={onClose} size="lg" title={tt(schedule ? 'editSchedule' : 'addSchedule')}
      footer={<><Button variant="secondary" onClick={onClose}>{t('ui.cancel')}</Button><Button loading={busy} onClick={() => run(() => source.saveSchedule({ name, is_default: isDefault, days }, schedule?.id))}>{tt('save')}</Button></>}>
      <div className="grid gap-4 sm:grid-cols-2">
        <Input label={tt('name')} required value={name} onChange={(e) => setName(e.target.value)} error={fields.name} />
        <div className="flex items-end"><Checkbox label={tt('defaultSchedule')} description={tt('defaultScheduleHint')} checked={isDefault} onChange={(e) => setDefault(e.target.checked)} /></div>
        {WEEKDAYS.map((d) => (
          <Select key={d} label={tt(`weekdayLong.${d}`)} placeholder={tt('rest')} value={days[d] ?? ''} onChange={(e) => setDays({ ...days, [d]: e.target.value || null })} options={options} />
        ))}
        {fields.days && <p role="alert" className="text-sm text-danger-fg sm:col-span-2">{fields.days}</p>}
        <FormError message={formError(error, tt('actionFailed'))} />
      </div>
    </Modal>
  );
}
