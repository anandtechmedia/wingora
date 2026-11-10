import { useCallback, useState } from 'react';
import { Permission as P } from '@wingora/permissions';
import { ApiNotConnectedError } from '@/api/dashboard';
import {
  usePayrollSource, type CalendarInput, type CompensationInput, type CompensationRow, type ComponentInput, type Loan, type LoanInput,
  type MyPayslip, type PayFrequency, type PayrollRun, type Reimbursement, type ReimbursementInput, type RunType, type SalaryStructure, type Scope,
} from '@/api/payroll';
import { Badge, Button, Card, DataTable, EmptyState, Input, Modal, Select, Tabs, useToast, type DataTableColumn } from '@/components/ui';
import { useSession } from '@/auth/session';
import { PageHeader } from '@/shell/page-header';
import { errorMessage, FormError, formError, ResourceView, useResource, validationErrors } from '@/modules/employees/shared';
import { Pager } from '@/modules/attendance';
import { DecisionDialog, TextArea } from '@/modules/time/shared';
import { RunDetail } from './run-detail';
import { ClaimStatusBadge, Disclaimer, FileField, PayrollNotConnected, RunStatusBadge, SourceBadge, useAction, usePayrollT } from './shared';

const FREQUENCIES: PayFrequency[] = ['weekly', 'biweekly', 'semimonthly', 'monthly'];
const CATEGORIES = ['travel', 'meals', 'lodging', 'equipment', 'training', 'communication', 'other'];
const today = () => new Date().toISOString().slice(0, 10);

function useCan() {
  const { permissions } = useSession();
  return (...p: string[]) => p.some((x) => permissions.has(x));
}

export default function Payroll() {
  const { pt, t } = usePayrollT();
  const can = useCan();
  const tabs = [
    can(P.PayslipsViewSelf, P.ReimbursementsSubmit) && { id: 'mine', label: pt('myPay'), content: <MyPay /> },
    can(P.PayrollView) && { id: 'runs', label: pt('runs'), content: <Runs /> },
    can(P.ReimbursementsApprove, P.PayrollManage) && { id: 'reimbursements', label: pt('reimbursements'), content: <div className="space-y-6">
      <ReimbursementsCard scope="approvals" title={pt('reimbursementApprovals')} />
      {can(P.PayrollManage) && <ReimbursementsCard scope="all" title={pt('allReimbursements')} />}</div> },
    can(P.PayrollView) && { id: 'compensation', label: pt('compensation'), content: <Compensation /> },
    can(P.PayrollView) && { id: 'loans', label: pt('loans'), content: <LoansCard scope="all" /> },
    can(P.PayrollConfigure) && { id: 'setup', label: pt('setup'), content: <Setup /> },
  ].filter((x): x is { id: string; label: string; content: React.ReactElement } => Boolean(x));
  return (
    <>
      <PageHeader title={t('nav.module.payroll')} description={pt('subtitle')} />
      {tabs.length > 0 ? <Tabs label={t('nav.module.payroll')} tabs={tabs} /> : <EmptyState title={pt('nothingToShow')} />}
    </>
  );
}

function MyPay() {
  const { pt, money } = usePayrollT();
  const source = usePayrollSource();
  const can = useCan();
  const { busy, run } = useAction();
  const [page, setPage] = useState(1);
  const slips = useResource(useCallback((signal: AbortSignal) => (can(P.PayslipsViewSelf) ? source.myPayslips(page, signal) : Promise.resolve(null)), [source, page])); // eslint-disable-line react-hooks/exhaustive-deps
  const columns: DataTableColumn<MyPayslip>[] = [
    { id: 'period', header: pt('period'), primary: true, cell: (s) => `${s.period.start_date} – ${s.period.end_date}${s.run_type === 'off_cycle' ? ` · ${pt('runType.off_cycle')}` : ''}` },
    { id: 'pay', header: pt('payDate'), cell: (s) => s.period.pay_date },
    { id: 'gross', header: pt('gross'), cell: (s) => money(s.gross) },
    { id: 'deductions', header: pt('deductions'), cell: (s) => money(s.deductions) },
    { id: 'net', header: pt('net'), cell: (s) => <span className="font-medium">{money(s.net)}</span> },
    { id: 'pdf', header: pt('actions'), cell: (s) => <Button size="sm" variant="tertiary" loading={busy === s.id} onClick={() => run(s.id, () => source.downloadPayslip(s.id))}>{pt('downloadPdf')}</Button> },
  ];
  return (
    <div className="space-y-6">
      {can(P.PayslipsViewSelf) && <Card padding="none" title={pt('myPayslips')} headingLevel={2} description={pt('myPayslipsHint')}>
        {slips.error instanceof ApiNotConnectedError ? <PayrollNotConnected /> : <>
          <DataTable caption={pt('myPayslips')} hideCaption columns={columns} rows={slips.data?.data ?? []} getRowId={(s) => s.id} loading={slips.loading}
            error={slips.error ? errorMessage(slips.error, pt('loadError')) : null} onRetry={slips.reload} empty={<EmptyState title={pt('noPayslips')} />} />
          <Pager meta={slips.data?.meta} onPage={setPage} />
        </>}
      </Card>}
      {can(P.ReimbursementsSubmit) && <ReimbursementsCard scope="mine" title={pt('myReimbursements')} />}
      {can(P.PayslipsViewSelf) && <LoansCard scope="mine" />}
    </div>
  );
}

function Runs() {
  const { pt, money } = usePayrollT();
  const source = usePayrollSource();
  const can = useCan();
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);
  const [selected, setSelected] = useState<string | null>(null);
  const [creating, setCreating] = useState(false);
  const runs = useResource(useCallback((signal: AbortSignal) => { void selected; return source.runs({ status, page }, signal); }, [source, status, page, selected]));
  if (selected) return <RunDetail id={selected} onBack={() => setSelected(null)} />;
  const columns: DataTableColumn<PayrollRun>[] = [
    { id: 'period', header: pt('period'), primary: true, cell: (r) => <div><div className="font-medium text-fg">{r.period.start_date} – {r.period.end_date}</div><div className="text-xs text-fg-muted">{r.period.calendar} · {pt(`runType.${r.type}`)}</div></div> },
    { id: 'entity', header: pt('legalEntity'), cell: (r) => `${r.legal_entity.name} (${r.currency})` },
    { id: 'status', header: pt('statusLabel'), cell: (r) => <div className="flex flex-col gap-1"><RunStatusBadge status={r.status} />
      {r.status === 'in_review' && <span className="text-xs text-fg-muted">{pt('levelOf', { level: r.current_level, total: r.approval_levels })}</span>}</div> },
    { id: 'employees', header: pt('employees'), cell: (r) => r.employee_count },
    { id: 'net', header: pt('net'), cell: (r) => money(r.net) },
    { id: 'open', header: pt('actions'), cell: (r) => <Button size="sm" variant="tertiary" onClick={() => setSelected(r.id)}>{pt('open')}</Button> },
  ];
  return (
    <div className="space-y-4">
      <Disclaimer />
      <Card padding="none" title={pt('runs')} headingLevel={2} actions={<div className="flex flex-wrap items-end gap-2">
        <Select label={pt('statusLabel')} hideLabel value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }} placeholder={pt('allStatuses')}
          options={(['draft', 'in_review', 'approved', 'published'] as const).map((s) => ({ value: s, label: pt(`status.${s}`) }))} />
        {can(P.PayrollManage) && <Button size="sm" onClick={() => setCreating(true)}>{pt('newRun')}</Button>}
      </div>}>
        {runs.error instanceof ApiNotConnectedError ? <PayrollNotConnected /> : <>
          <DataTable caption={pt('runs')} hideCaption columns={columns} rows={runs.data?.data ?? []} getRowId={(r) => r.id} loading={runs.loading}
            error={runs.error ? errorMessage(runs.error, pt('loadError')) : null} onRetry={runs.reload} empty={<EmptyState title={pt('noRuns')} description={pt('noRunsHint')} />} />
          <Pager meta={runs.data?.meta} onPage={setPage} />
        </>}
      </Card>
      {creating && <NewRunDialog onClose={() => setCreating(false)} onCreated={(id) => setSelected(id)} />}
    </div>
  );
}

function NewRunDialog({ onClose, onCreated }: { onClose: () => void; onCreated: (id: string) => void }) {
  const { pt, t } = usePayrollT();
  const source = usePayrollSource();
  const periods = useResource(useCallback((signal: AbortSignal) => source.periods(signal), [source]));
  const [period, setPeriod] = useState('');
  const [type, setType] = useState<RunType>('regular');
  const { error, busy, save, fields } = useSave();
  return (
    <Modal open onClose={onClose} title={pt('newRun')} description={pt('newRunHint')}
      footer={<><Button variant="secondary" onClick={onClose}>{t('ui.cancel')}</Button>
        <Button loading={busy} disabled={!period} onClick={() => save(async () => { const r = await source.createRun(period, type); onClose(); onCreated(r.id); })}>{pt('create')}</Button></>}>
      <div className="grid gap-4 sm:grid-cols-2">
        <Select label={pt('period')} required value={period} onChange={(e) => setPeriod(e.target.value)} placeholder={periods.loading ? pt('loading') : pt('choosePeriod')} error={fields.period}
          options={(periods.data?.data ?? []).map((p) => ({ value: p.id, label: `${p.calendar?.legal_entity ?? ''} · ${p.start_date} – ${p.end_date}${p.runs?.length ? ` (${p.runs.map((r) => pt(`status.${r.status}`)).join(', ')})` : ''}` }))} />
        <Select label={pt('runTypeLabel')} value={type} onChange={(e) => setType(e.target.value as RunType)} hint={pt('offCycleHint')}
          options={(['regular', 'off_cycle'] as const).map((x) => ({ value: x, label: pt(`runType.${x}`) }))} />
        {periods.data && periods.data.data.length === 0 && <p className="text-sm text-fg-muted sm:col-span-2">{pt('noPeriods')}</p>}
        <FormError message={(error ? formError(error, pt('actionFailed')) : null)} />
      </div>
    </Modal>
  );
}

function useSave() {
  const [error, setError] = useState<unknown>(null);
  const [busy, setBusy] = useState(false);
  const save = async (fn: () => Promise<unknown>) => {
    setBusy(true);
    setError(null);
    try {
      await fn();
    } catch (e) {
      setError(e);
    } finally {
      setBusy(false);
    }
  };
  return { error, busy, save, fields: validationErrors(error) };
}

function ReimbursementsCard({ scope, title }: { scope: Scope; title: string }) {
  const { pt, money } = usePayrollT();
  const source = usePayrollSource();
  const { toast } = useToast();
  const { busy, run } = useAction();
  const [page, setPage] = useState(1);
  const [submitting, setSubmitting] = useState(false);
  const [decision, setDecision] = useState<{ item: Reimbursement; approve: boolean } | null>(null);
  const list = useResource(useCallback((signal: AbortSignal) => source.reimbursements(scope, page, signal), [source, scope, page]));
  const columns: DataTableColumn<Reimbursement>[] = [
    ...(scope !== 'mine' ? [{ id: 'employee', header: pt('employee'), primary: true, cell: (r: Reimbursement) => r.employee.name }] : []),
    { id: 'date', header: pt('expenseDate'), primary: scope === 'mine', cell: (r) => r.expense_date },
    { id: 'category', header: pt('categoryLabel'), cell: (r) => <div><div>{pt(`category.${r.category}`)}</div><div className="text-xs text-fg-muted">{r.description}</div></div> },
    { id: 'amount', header: pt('amount'), cell: (r) => money(r.amount) },
    { id: 'status', header: pt('statusLabel'), cell: (r) => <ClaimStatusBadge status={r.status} /> },
    { id: 'actions', header: pt('actions'), cell: (r) => <div className="flex flex-wrap gap-2">
      {r.has_receipt && <Button size="sm" variant="tertiary" loading={busy === r.id} onClick={() => run(r.id, () => source.downloadReceipt(r.id))}>{pt('receipt')}</Button>}
      {r.can_decide && <><Button size="sm" onClick={() => setDecision({ item: r, approve: true })}>{pt('approve')}</Button><Button size="sm" variant="secondary" onClick={() => setDecision({ item: r, approve: false })}>{pt('reject')}</Button></>}
      {r.can_cancel && scope === 'mine' && <Button size="sm" variant="tertiary" loading={busy === `c${r.id}`} onClick={() => run(`c${r.id}`, () => source.cancelReimbursement(r.id), pt('cancelled')).then((ok) => ok && list.reload())}>{pt('cancel')}</Button>}
    </div> },
  ];
  return (
    <Card padding="none" title={title} headingLevel={2} actions={scope === 'mine' ? <Button size="sm" onClick={() => setSubmitting(true)}>{pt('submitClaim')}</Button> : undefined}>
      {list.error instanceof ApiNotConnectedError ? <PayrollNotConnected /> : <>
        <DataTable caption={title} hideCaption columns={columns} rows={list.data?.data ?? []} getRowId={(r) => r.id} loading={list.loading}
          error={list.error ? errorMessage(list.error, pt('loadError')) : null} onRetry={list.reload} empty={<EmptyState title={pt('noReimbursements')} />} />
        <Pager meta={list.data?.meta} onPage={setPage} />
      </>}
      {submitting && <ClaimDialog onClose={() => setSubmitting(false)} onSaved={list.reload} />}
      {decision && <DecisionDialog approve={decision.approve} subject={`${decision.item.employee.name} · ${money(decision.item.amount)}`} onClose={() => setDecision(null)}
        onDecide={async (note) => { await source.decideReimbursement(decision.item.id, decision.approve, note); toast({ title: pt(decision.approve ? 'approved' : 'rejected'), tone: 'success' }); list.reload(); }} />}
    </Card>
  );
}

function ClaimDialog({ onClose, onSaved }: { onClose: () => void; onSaved: () => void }) {
  const { pt, t } = usePayrollT();
  const source = usePayrollSource();
  const [f, setF] = useState<ReimbursementInput>({ amount: '', category: 'travel', description: '', expense_date: today(), receipt: null });
  const { error, busy, save, fields } = useSave();
  return (
    <Modal open onClose={onClose} title={pt('submitClaim')} description={pt('claimHint')}
      footer={<><Button variant="secondary" onClick={onClose}>{t('ui.cancel')}</Button><Button loading={busy} onClick={() => save(async () => { await source.submitReimbursement(f); onSaved(); onClose(); })}>{pt('submit')}</Button></>}>
      <div className="grid gap-4 sm:grid-cols-2">
        <Input label={pt('amount')} required inputMode="decimal" value={f.amount} onChange={(e) => setF({ ...f, amount: e.target.value })} error={fields.amount} />
        <Input label={pt('expenseDate')} type="date" required value={f.expense_date} onChange={(e) => setF({ ...f, expense_date: e.target.value })} error={fields.expense_date} />
        <Select label={pt('categoryLabel')} value={f.category} onChange={(e) => setF({ ...f, category: e.target.value })} options={CATEGORIES.map((c) => ({ value: c, label: pt(`category.${c}`) }))} />
        <TextArea label={pt('description')} required value={f.description} onChange={(v) => setF({ ...f, description: v })} error={fields.description} />
        <FileField label={pt('receiptOptional')} accept=".pdf,.png,.jpg,.jpeg" onFile={(receipt) => setF({ ...f, receipt })} />
        {fields.receipt_id && <p role="alert" className="text-sm text-danger-fg sm:col-span-2">{fields.receipt_id}</p>}
        <FormError message={(error ? formError(error, pt('actionFailed')) : null)} />
      </div>
    </Modal>
  );
}

function LoansCard({ scope }: { scope: 'mine' | 'all' }) {
  const { pt, money } = usePayrollT();
  const source = usePayrollSource();
  const can = useCan();
  const { busy, run } = useAction();
  const [page, setPage] = useState(1);
  const [creating, setCreating] = useState(false);
  const loans = useResource(useCallback((signal: AbortSignal) => source.loans(scope, page, signal), [source, scope, page]));
  const manage = scope === 'all' && can(P.PayrollManage);
  const columns: DataTableColumn<Loan>[] = [
    ...(scope === 'all' ? [{ id: 'employee', header: pt('employee'), primary: true, cell: (l: Loan) => l.employee.name }] : []),
    { id: 'principal', header: pt('principal'), primary: scope === 'mine', cell: (l) => money(l.principal) },
    { id: 'installment', header: pt('installment'), cell: (l) => money(l.installment) },
    { id: 'outstanding', header: pt('outstanding'), cell: (l) => money(l.outstanding) },
    { id: 'from', header: pt('firstDeduction'), cell: (l) => l.first_deduction_on },
    { id: 'status', header: pt('statusLabel'), cell: (l) => <Badge size="sm" tone={l.status === 'active' ? 'warning' : 'neutral'}>{pt(`loanStatus.${l.status}`)}</Badge> },
    ...(manage ? [{ id: 'actions', header: pt('actions'), cell: (l: Loan) => (l.status === 'active'
      ? <Button size="sm" variant="tertiary" loading={busy === l.id} onClick={() => run(l.id, () => source.cancelLoan(l.id), pt('cancelled')).then((ok) => ok && loans.reload())}>{pt('cancel')}</Button> : null) }] : []),
  ];
  return (
    <Card padding="none" title={pt(scope === 'mine' ? 'myLoans' : 'loans')} headingLevel={2} description={pt('loansHint')} actions={manage ? <Button size="sm" onClick={() => setCreating(true)}>{pt('newLoan')}</Button> : undefined}>
      {loans.error instanceof ApiNotConnectedError ? <PayrollNotConnected /> : <>
        <DataTable caption={pt('loans')} hideCaption columns={columns} rows={loans.data?.data ?? []} getRowId={(l) => l.id} loading={loans.loading}
          error={loans.error ? errorMessage(loans.error, pt('loadError')) : null} onRetry={loans.reload} empty={<EmptyState title={pt('noLoans')} />} />
        <Pager meta={loans.data?.meta} onPage={setPage} />
      </>}
      {creating && <LoanDialog onClose={() => setCreating(false)} onSaved={loans.reload} />}
    </Card>
  );
}

function LoanDialog({ onClose, onSaved }: { onClose: () => void; onSaved: () => void }) {
  const { pt, t } = usePayrollT();
  const source = usePayrollSource();
  const [f, setF] = useState<LoanInput>({ employee: '', principal: '', installment: '', first_deduction_on: today(), reason: '' });
  const { error, busy, save, fields } = useSave();
  return (
    <Modal open onClose={onClose} title={pt('newLoan')} description={pt('loanHint')}
      footer={<><Button variant="secondary" onClick={onClose}>{t('ui.cancel')}</Button><Button loading={busy} onClick={() => save(async () => { await source.createLoan(f); onSaved(); onClose(); })}>{pt('create')}</Button></>}>
      <div className="grid gap-4 sm:grid-cols-2">
        <Input label={pt('employeeId')} hint={pt('employeeIdHint')} required value={f.employee} onChange={(e) => setF({ ...f, employee: e.target.value.trim() })} error={fields.employee} />
        <Input label={pt('firstDeduction')} type="date" required value={f.first_deduction_on} onChange={(e) => setF({ ...f, first_deduction_on: e.target.value })} error={fields.first_deduction_on} />
        <Input label={pt('principal')} required inputMode="decimal" value={f.principal} onChange={(e) => setF({ ...f, principal: e.target.value })} error={fields.principal} />
        <Input label={pt('installment')} required inputMode="decimal" value={f.installment} onChange={(e) => setF({ ...f, installment: e.target.value })} error={fields.installment} />
        <TextArea label={pt('reason')} required value={f.reason} onChange={(v) => setF({ ...f, reason: v })} error={fields.reason} />
        <FormError message={(error ? formError(error, pt('actionFailed')) : null)} />
      </div>
    </Modal>
  );
}

function Compensation() {
  const { pt, money } = usePayrollT();
  const source = usePayrollSource();
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [open, setOpen] = useState<CompensationRow | null>(null);
  const rows = useResource(useCallback((signal: AbortSignal) => { void open; return source.compensation({ search, page }, signal); }, [source, search, page, open]));
  const columns: DataTableColumn<CompensationRow>[] = [
    { id: 'employee', header: pt('employee'), primary: true, cell: (r) => <div><div className="font-medium text-fg">{r.employee.name}</div><div className="text-xs text-fg-muted">{r.employee.employee_number}</div></div> },
    { id: 'entity', header: pt('legalEntity'), cell: (r) => r.legal_entity ?? '—' },
    { id: 'amount', header: pt('currentPay'), cell: (r) => (r.current ? `${money(r.current.amount)} · ${pt(`basis.${r.current.basis}`)}` : pt('noCompensation')) },
    { id: 'frequency', header: pt('payFrequency'), cell: (r) => (r.current ? pt(`frequency.${r.current.pay_frequency}`) : '—') },
    { id: 'from', header: pt('effectiveFrom'), cell: (r) => r.current?.effective_from ?? '—' },
    { id: 'actions', header: pt('actions'), cell: (r) => <Button size="sm" variant="tertiary" onClick={() => setOpen(r)}>{pt('history')}</Button> },
  ];
  return (
    <Card padding="none" title={pt('compensation')} headingLevel={2} description={pt('compensationHint')}
      actions={<Input label={pt('search')} hideLabel placeholder={pt('searchEmployees')} value={search} onChange={(e) => { setSearch(e.target.value); setPage(1); }} />}>
      {rows.error instanceof ApiNotConnectedError ? <PayrollNotConnected /> : <>
        <DataTable caption={pt('compensation')} hideCaption columns={columns} rows={rows.data?.data ?? []} getRowId={(r) => r.employee.id} loading={rows.loading}
          error={rows.error ? errorMessage(rows.error, pt('loadError')) : null} onRetry={rows.reload} empty={<EmptyState title={pt('noEmployees')} />} />
        <Pager meta={rows.data?.meta} onPage={setPage} />
      </>}
      {open && <HistoryDialog row={open} onClose={() => setOpen(null)} />}
    </Card>
  );
}

function HistoryDialog({ row, onClose }: { row: CompensationRow; onClose: () => void }) {
  const { pt, t, money } = usePayrollT();
  const source = usePayrollSource();
  const can = useCan();
  const [version, setVersion] = useState(0);
  const [adding, setAdding] = useState(false);
  const history = useResource(useCallback((signal: AbortSignal) => { void version; return source.history(row.employee.id, signal); }, [source, row.employee.id, version]));
  return (
    <Modal open onClose={onClose} size="lg" title={`${row.employee.name} · ${pt('compensationHistory')}`} description={pt('historyHint')}
      footer={<>{can(P.PayrollManage) && <Button variant="secondary" onClick={() => setAdding(true)}>{pt('addRecord')}</Button>}<Button onClick={onClose}>{t('ui.close')}</Button></>}>
      <ResourceView resource={history} empty={pt('noCompensation')} isEmpty={(d) => d.length === 0}>
        {(list) => <ol className="divide-y divide-line text-sm">{list.map((r) => (
          <li key={r.id} className="py-2">
            <div className="flex flex-wrap items-center justify-between gap-2"><span className="font-medium text-fg">{money(r.amount)} · {pt(`basis.${r.basis}`)} · {pt(`frequency.${r.pay_frequency}`)}</span>
              <span className="text-fg-muted">{r.effective_from} – {r.effective_to ?? pt('current')}</span></div>
            <div className="text-xs text-fg-muted">{r.reason}{r.salary_structure ? ` · ${r.salary_structure.name}` : ''}{r.created_by ? ` · ${r.created_by}` : ''} <SourceBadge source="manual" /></div>
          </li>))}</ol>}
      </ResourceView>
      {adding && <CompensationDialog employeeId={row.employee.id} onClose={() => setAdding(false)} onSaved={() => setVersion((v) => v + 1)} />}
    </Modal>
  );
}

function CompensationDialog({ employeeId, onClose, onSaved }: { employeeId: string; onClose: () => void; onSaved: () => void }) {
  const { pt, t } = usePayrollT();
  const source = usePayrollSource();
  const structures = useResource(useCallback((signal: AbortSignal) => source.structures(signal), [source]));
  const [f, setF] = useState<CompensationInput>({ amount: '', basis: 'annual', pay_frequency: 'monthly', effective_from: today(), salary_structure: null, reason: '' });
  const { error, busy, save, fields } = useSave();
  return (
    <Modal open onClose={onClose} title={pt('addRecord')} description={pt('addRecordHint')}
      footer={<><Button variant="secondary" onClick={onClose}>{t('ui.cancel')}</Button><Button loading={busy} onClick={() => save(async () => { await source.addCompensation(employeeId, f); onSaved(); onClose(); })}>{pt('save')}</Button></>}>
      <div className="grid gap-4 sm:grid-cols-2">
        <Input label={pt('amount')} required inputMode="decimal" value={f.amount} onChange={(e) => setF({ ...f, amount: e.target.value })} error={fields.amount} hint={pt('currencyHint')} />
        <Select label={pt('basisLabel')} value={f.basis} onChange={(e) => setF({ ...f, basis: e.target.value as CompensationInput['basis'] })} options={(['annual', 'monthly', 'period'] as const).map((b) => ({ value: b, label: pt(`basis.${b}`) }))} />
        <Select label={pt('payFrequency')} value={f.pay_frequency} onChange={(e) => setF({ ...f, pay_frequency: e.target.value as PayFrequency })} options={FREQUENCIES.map((x) => ({ value: x, label: pt(`frequency.${x}`) }))} error={fields.pay_frequency} />
        <Input label={pt('effectiveFrom')} type="date" required value={f.effective_from} onChange={(e) => setF({ ...f, effective_from: e.target.value })} error={fields.effective_from} />
        <Select label={pt('structure')} placeholder={pt('noStructure')} value={f.salary_structure ?? ''} onChange={(e) => setF({ ...f, salary_structure: e.target.value || null })} error={fields.salary_structure}
          options={(structures.data ?? []).filter((s) => s.status === 'active').map((s) => ({ value: s.id, label: `${s.name} (${s.legal_entity.name})` }))} />
        <TextArea label={pt('reason')} required value={f.reason} onChange={(v) => setF({ ...f, reason: v })} error={fields.reason} />
        <FormError message={(error ? formError(error, pt('actionFailed')) : null)} />
      </div>
    </Modal>
  );
}

function Setup() {
  const { pt, money } = usePayrollT();
  const source = usePayrollSource();
  const { busy, run } = useAction();
  const [version, setVersion] = useState(0);
  const [calendar, setCalendar] = useState(false);
  const [structure, setStructure] = useState(false);
  const [component, setComponent] = useState<SalaryStructure | null>(null);
  const calendars = useResource(useCallback((signal: AbortSignal) => { void version; return source.calendars(signal); }, [source, version]));
  const periods = useResource(useCallback((signal: AbortSignal) => { void version; return source.periods(signal); }, [source, version]));
  const structures = useResource(useCallback((signal: AbortSignal) => { void version; return source.structures(signal); }, [source, version]));
  const refresh = () => setVersion((v) => v + 1);
  return (
    <div className="space-y-6">
      <Disclaimer />
      <Card title={pt('calendars')} headingLevel={2} description={pt('calendarsHint')} actions={<Button size="sm" onClick={() => setCalendar(true)}>{pt('newCalendar')}</Button>}>
        {calendars.error instanceof ApiNotConnectedError ? <PayrollNotConnected /> : <ResourceView resource={calendars} empty={pt('noCalendars')} isEmpty={(d) => d.length === 0}>
          {(list) => <ul className="divide-y divide-line">{list.map((c) => (
            <li key={c.id} className="flex flex-wrap items-center justify-between gap-2 py-3 text-sm">
              <div><span className="font-medium text-fg">{c.name}</span> <span className="text-fg-muted">· {c.legal_entity.name} ({c.legal_entity.currency_code}) · {pt(`frequency.${c.pay_frequency}`)} · {pt('approvalLevelsCount', { levels: c.approval_levels })}</span></div>
              <Button size="sm" variant="tertiary" loading={busy === c.id} onClick={() => run(c.id, () => source.generatePeriods(c.id, 3), pt('periodsGenerated')).then((ok) => ok && refresh())}>{pt('generatePeriods')}</Button>
            </li>))}</ul>}
        </ResourceView>}
      </Card>
      <Card title={pt('periods')} headingLevel={2}>
        <ResourceView resource={periods} empty={pt('noPeriods')} isEmpty={(d) => d.data.length === 0}>
          {(page) => <ul className="divide-y divide-line text-sm">{page.data.map((p) => (
            <li key={p.id} className="flex flex-wrap justify-between gap-2 py-2">
              <span><span className="font-medium text-fg">{p.start_date} – {p.end_date}</span> <span className="text-fg-muted">· {p.calendar?.name} · {pt('payDate')}: {p.pay_date}</span></span>
              <span className="flex gap-1">{(p.runs ?? []).map((r) => <RunStatusBadge key={r.id} status={r.status} />)}</span>
            </li>))}</ul>}
        </ResourceView>
      </Card>
      <Card title={pt('structures')} headingLevel={2} description={pt('structuresHint')} actions={<Button size="sm" onClick={() => setStructure(true)}>{pt('newStructure')}</Button>}>
        <ResourceView resource={structures} empty={pt('noStructures')} isEmpty={(d) => d.length === 0}>
          {(list) => <ul className="space-y-4">{list.map((s) => (
            <li key={s.id} className="rounded-lg border border-line p-4 text-sm">
              <div className="flex flex-wrap items-center justify-between gap-2"><span><span className="font-medium text-fg">{s.name}</span> <span className="text-fg-muted">({s.code}) · {s.legal_entity.name}</span></span>
                <Button size="sm" variant="tertiary" onClick={() => setComponent(s)}>{pt('addComponent')}</Button></div>
              {s.components.length === 0 ? <p className="mt-2 text-fg-muted">{pt('noComponents')}</p> : <ul className="mt-2 divide-y divide-line">{s.components.map((c) => (
                <li key={c.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
                  <span>{c.name} <span className="text-fg-muted">({c.code} · {pt(`kind.${c.kind}`)} · {c.method === 'fixed' ? money(c.amount) : c.method === 'percent_of_base' ? pt('percentOfBase', { rate: c.rate_percent ?? '' }) : pt(`method.${c.method}`)})</span> <SourceBadge source={c.source} /></span>
                  <span className="flex items-center gap-2 text-xs text-fg-muted">{c.effective_from} – {c.effective_to ?? pt('current')}
                    {!c.effective_to && <Button size="sm" variant="tertiary" loading={busy === c.id} onClick={() => run(c.id, () => source.endComponent(s.id, c.id, today()), pt('saved')).then((ok) => ok && refresh())}>{pt('endToday')}</Button>}</span>
                </li>))}</ul>}
            </li>))}</ul>}
        </ResourceView>
      </Card>
      {calendar && <CalendarDialog onClose={() => setCalendar(false)} onSaved={refresh} />}
      {structure && <StructureDialog onClose={() => setStructure(false)} onSaved={refresh} />}
      {component && <ComponentDialog structure={component} onClose={() => setComponent(null)} onSaved={refresh} />}
    </div>
  );
}

function useEntities() {
  const source = usePayrollSource();
  return useResource(useCallback((signal: AbortSignal) => source.legalEntities(signal), [source]));
}

function CalendarDialog({ onClose, onSaved }: { onClose: () => void; onSaved: () => void }) {
  const { pt, t } = usePayrollT();
  const source = usePayrollSource();
  const entities = useEntities();
  const [f, setF] = useState<CalendarInput>({ legal_entity: '', name: '', pay_frequency: 'monthly', first_period_start: today().slice(0, 8) + '01', pay_date_offset_days: 0, approval_levels: 2 });
  const { error, busy, save, fields } = useSave();
  return (
    <Modal open onClose={onClose} title={pt('newCalendar')} description={pt('calendarHint')}
      footer={<><Button variant="secondary" onClick={onClose}>{t('ui.cancel')}</Button><Button loading={busy} onClick={() => save(async () => { await source.saveCalendar(f); onSaved(); onClose(); })}>{pt('create')}</Button></>}>
      <div className="grid gap-4 sm:grid-cols-2">
        <Select label={pt('legalEntity')} required placeholder={pt('chooseEntity')} value={f.legal_entity ?? ''} onChange={(e) => setF({ ...f, legal_entity: e.target.value })} error={fields.legal_entity}
          options={(entities.data ?? []).map((e) => ({ value: e.id, label: `${e.name} (${e.country_code})` }))} />
        <Input label={pt('name')} required value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} error={fields.name} />
        <Select label={pt('payFrequency')} value={f.pay_frequency ?? 'monthly'} onChange={(e) => setF({ ...f, pay_frequency: e.target.value as PayFrequency })} error={fields.pay_frequency} hint={pt('frequencyHint')}
          options={FREQUENCIES.map((x) => ({ value: x, label: pt(`frequency.${x}`) }))} />
        <Input label={pt('firstPeriodStart')} type="date" required value={f.first_period_start ?? ''} onChange={(e) => setF({ ...f, first_period_start: e.target.value })} error={fields.first_period_start} />
        <Select label={pt('approvalLevels')} value={String(f.approval_levels)} onChange={(e) => setF({ ...f, approval_levels: Number(e.target.value) })} error={fields.approval_levels}
          options={[1, 2, 3].map((n) => ({ value: String(n), label: String(n) }))} />
        <Input label={pt('payDateOffset')} type="number" min={0} max={31} value={String(f.pay_date_offset_days)} onChange={(e) => setF({ ...f, pay_date_offset_days: Number(e.target.value) })} error={fields.pay_date_offset_days} />
        <FormError message={(error ? formError(error, pt('actionFailed')) : null)} />
      </div>
    </Modal>
  );
}

function StructureDialog({ onClose, onSaved }: { onClose: () => void; onSaved: () => void }) {
  const { pt, t } = usePayrollT();
  const source = usePayrollSource();
  const entities = useEntities();
  const [f, setF] = useState({ legal_entity: '', name: '', code: '' });
  const { error, busy, save, fields } = useSave();
  return (
    <Modal open onClose={onClose} title={pt('newStructure')}
      footer={<><Button variant="secondary" onClick={onClose}>{t('ui.cancel')}</Button><Button loading={busy} onClick={() => save(async () => { await source.createStructure({ ...f, code: f.code.toUpperCase() }); onSaved(); onClose(); })}>{pt('create')}</Button></>}>
      <div className="grid gap-4 sm:grid-cols-2">
        <Select label={pt('legalEntity')} required placeholder={pt('chooseEntity')} value={f.legal_entity} onChange={(e) => setF({ ...f, legal_entity: e.target.value })} error={fields.legal_entity}
          options={(entities.data ?? []).map((e) => ({ value: e.id, label: `${e.name} (${e.country_code})` }))} />
        <Input label={pt('name')} required value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} error={fields.name} />
        <Input label={pt('code')} required value={f.code} onChange={(e) => setF({ ...f, code: e.target.value })} error={fields.code} />
        <FormError message={(error ? formError(error, pt('actionFailed')) : null)} />
      </div>
    </Modal>
  );
}

function ComponentDialog({ structure, onClose, onSaved }: { structure: SalaryStructure; onClose: () => void; onSaved: () => void }) {
  const { pt, t } = usePayrollT();
  const source = usePayrollSource();
  const [f, setF] = useState<ComponentInput>({ code: '', name: '', kind: 'earning', method: 'fixed', amount: '', rate_percent: '', rule_key: '', effective_from: today() });
  const { error, busy, save, fields } = useSave();
  const payload = (): ComponentInput => ({ code: f.code.toUpperCase(), name: f.name, kind: f.kind, method: f.method, effective_from: f.effective_from, rule_key: f.rule_key || null,
    ...(f.method === 'fixed' ? { amount: f.amount } : {}), ...(f.method === 'percent_of_base' ? { rate_percent: f.rate_percent } : {}) });
  return (
    <Modal open onClose={onClose} title={`${pt('addComponent')} · ${structure.name}`} description={pt('componentHint')}
      footer={<><Button variant="secondary" onClick={onClose}>{t('ui.cancel')}</Button><Button loading={busy} onClick={() => save(async () => { await source.addComponent(structure.id, payload()); onSaved(); onClose(); })}>{pt('save')}</Button></>}>
      <div className="grid gap-4 sm:grid-cols-2">
        <Input label={pt('code')} required value={f.code} onChange={(e) => setF({ ...f, code: e.target.value })} error={fields.code} />
        <Input label={pt('name')} required value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} error={fields.name} />
        <Select label={pt('kindLabel')} value={f.kind} onChange={(e) => setF({ ...f, kind: e.target.value as ComponentInput['kind'] })} options={(['earning', 'deduction'] as const).map((k) => ({ value: k, label: pt(`kind.${k}`) }))} />
        <Select label={pt('methodLabel')} value={f.method} onChange={(e) => setF({ ...f, method: e.target.value as ComponentInput['method'] })} error={fields.method}
          options={(['fixed', 'percent_of_base', 'manual', 'provider'] as const).map((m) => ({ value: m, label: pt(`method.${m}`) }))} />
        {f.method === 'fixed' && <Input label={pt('amount')} required inputMode="decimal" hint={structure.legal_entity.currency_code} value={f.amount ?? ''} onChange={(e) => setF({ ...f, amount: e.target.value })} error={fields.amount} />}
        {f.method === 'percent_of_base' && <Input label={pt('ratePercent')} required inputMode="decimal" value={f.rate_percent ?? ''} onChange={(e) => setF({ ...f, rate_percent: e.target.value })} error={fields.rate_percent} />}
        <Input label={pt('ruleKey')} hint={pt('ruleKeyHint')} value={f.rule_key ?? ''} onChange={(e) => setF({ ...f, rule_key: e.target.value })} error={fields.rule_key} />
        <Input label={pt('effectiveFrom')} type="date" required value={f.effective_from} onChange={(e) => setF({ ...f, effective_from: e.target.value })} error={fields.effective_from} />
        <FormError message={(error ? formError(error, pt('actionFailed')) : null)} />
      </div>
    </Modal>
  );
}
