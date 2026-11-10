import { useCallback, useState } from 'react';
import { usePayrollSource, type LineInput, type Payslip } from '@/api/payroll';
import { Badge, Button, Card, Checkbox, DataTable, EmptyState, Input, Modal, Select, type DataTableColumn } from '@/components/ui';
import { errorMessage, FormError, formError, ResourceView, useResource, validationErrors } from '@/modules/employees/shared';
import { Pager } from '@/modules/attendance';
import { DecisionDialog, TextArea } from '@/modules/time/shared';
import { Disclaimer, FileField, RunStatusBadge, SourceBadge, useAction, usePayrollT } from './shared';

export function RunDetail({ id, onBack }: { id: string; onBack: () => void }) {
  const { pt, money } = usePayrollT();
  const source = usePayrollSource();
  const { busy, run: act } = useAction();
  const [version, setVersion] = useState(0);
  const [page, setPage] = useState(1);
  const [search, setSearch] = useState('');
  const [issuesOnly, setIssuesOnly] = useState(false);
  const [decision, setDecision] = useState<boolean | null>(null);
  const [importing, setImporting] = useState(false);
  const [open, setOpen] = useState<Payslip | null>(null);
  const detail = useResource(useCallback((signal: AbortSignal) => { void version; return source.run(id, signal); }, [source, id, version]));
  const slips = useResource(useCallback((signal: AbortSignal) => { void version; return source.payslips(id, { search, issues: issuesOnly, page }, signal); }, [source, id, search, issuesOnly, page, version]));
  const refresh = () => setVersion((v) => v + 1);
  const step = (key: string, fn: () => Promise<unknown>, done: string) => act(key, fn, done).then((ok) => ok && refresh());
  const columns: DataTableColumn<Payslip>[] = [
    { id: 'employee', header: pt('employee'), primary: true, cell: (s) => <div><div className="font-medium text-fg">{s.employee.name}</div><div className="text-xs text-fg-muted">{s.employee.employee_number}</div></div> },
    { id: 'days', header: pt('daysPaid'), cell: (s) => `${s.days_paid}/${s.period_days}` },
    { id: 'gross', header: pt('gross'), cell: (s) => money(s.gross) },
    { id: 'deductions', header: pt('deductions'), cell: (s) => money(s.deductions) },
    { id: 'net', header: pt('net'), cell: (s) => <span className="font-medium">{money(s.net)}</span> },
    { id: 'issues', header: pt('issues'), cell: (s) => (s.issues.length ? <Badge tone="warning" size="sm">{pt('issueCount', { count: s.issues.length })}</Badge> : '—') },
    { id: 'actions', header: pt('actions'), cell: (s) => <Button size="sm" variant="tertiary" onClick={() => setOpen(s)}>{pt('viewLines')}</Button> },
  ];
  return (
    <div className="space-y-6">
      <Button variant="tertiary" size="sm" onClick={onBack}>{pt('backToRuns')}</Button>
      <ResourceView resource={detail} empty="" isEmpty={() => false}>
        {(r) => (
          <div className="space-y-6">
            <Card headingLevel={2} title={<span className="flex flex-wrap items-center gap-2">{r.legal_entity.name} · {r.period.start_date} – {r.period.end_date} <RunStatusBadge status={r.status} /></span>}
              description={`${pt(`runType.${r.type}`)} · ${pt(`frequency.${r.period.pay_frequency}`)} · ${pt('payDate')}: ${r.period.pay_date} · ${r.currency}`}
              actions={<div className="flex flex-wrap gap-2">
                {r.can?.edit && <Button size="sm" variant="secondary" loading={busy === 'prepare'} onClick={() => step('prepare', () => source.prepare(id), pt('prepared'))}>{pt(r.prepared_at ? 'recalculate' : 'prepare')}</Button>}
                {r.can?.edit && <Button size="sm" variant="secondary" onClick={() => setImporting(true)}>{pt('importProvider')}</Button>}
                {r.can?.submit && <Button size="sm" loading={busy === 'submit'} onClick={() => step('submit', () => source.submit(id), pt('submitted'))}>{pt('submitForReview')}</Button>}
                {r.can?.approve && <><Button size="sm" onClick={() => setDecision(true)}>{pt('approveLevel', { level: r.current_level })}</Button><Button size="sm" variant="secondary" onClick={() => setDecision(false)}>{pt('reject')}</Button></>}
                {r.can?.publish && <Button size="sm" loading={busy === 'publish'} onClick={() => step('publish', () => source.publish(id), pt('publishedToast'))}>{pt('publish')}</Button>}
                {r.can?.export && (['summary', 'register', 'provider'] as const).map((f) => <Button key={f} size="sm" variant="tertiary" loading={busy === f} onClick={() => act(f, () => source.exportRun(id, f))}>{pt(`export.${f}`)}</Button>)}
              </div>}>
              <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                {[['gross', money(r.gross)], ['deductions', money(r.deductions)], ['net', money(r.net)], ['employees', String(r.employee_count)]].map(([k, v]) => (
                  <div key={k} className="rounded-lg border border-line p-3"><dt className="text-xs text-fg-muted">{pt(k!)}</dt><dd className="mt-1 text-lg font-semibold text-fg">{v}</dd></div>))}
              </dl>
              {r.rejection_note && r.status === 'draft' && <p role="alert" className="mt-4 text-sm text-danger-fg">{pt('rejectedBecause', { note: r.rejection_note })}</p>}
              {r.payslips_with_issues > 0 && <p className="mt-4 text-sm text-warning-fg">{pt('payslipsWithIssues', { count: r.payslips_with_issues })}</p>}
              <div className="mt-4"><Disclaimer /></div>
            </Card>
            <div className="grid gap-6 lg:grid-cols-2">
              <Card title={pt('totalsBySource')} headingLevel={2}>
                {r.totals_by_source.length === 0 ? <p className="text-sm text-fg-muted">{pt('notPrepared')}</p> : (
                  <table className="w-full text-sm"><caption className="sr-only">{pt('totalsBySource')}</caption>
                    <thead><tr className="text-left text-xs text-fg-muted"><th scope="col" className="py-1">{pt('sourceLabel')}</th><th scope="col">{pt('kindLabel')}</th><th scope="col">{pt('lines')}</th><th scope="col" className="text-right">{pt('total')}</th></tr></thead>
                    <tbody className="divide-y divide-line">{r.totals_by_source.map((x) => (
                      <tr key={`${x.source}-${x.kind}`}><td className="py-2"><SourceBadge source={x.source} /></td><td>{pt(`kind.${x.kind}`)}</td><td>{x.lines}</td><td className="text-right">{money(x.total)}</td></tr>))}</tbody>
                  </table>)}
              </Card>
              <Card title={pt('approvalTimeline')} headingLevel={2} description={pt('levelsRequired', { levels: r.approval_levels, cycle: r.review_cycle })}>
                <ol className="space-y-2 text-sm">
                  {r.prepared_by && <li>{pt('preparedBy', { name: r.prepared_by })}</li>}
                  {r.submitted_by && <li>{pt('submittedBy', { name: r.submitted_by })}</li>}
                  {r.approvals.map((a) => (
                    <li key={`${a.review_cycle}-${a.level}-${a.decided_at}`} className={a.decision === 'approved' ? 'text-success-fg' : 'text-danger-fg'}>
                      {pt(a.decision === 'approved' ? 'approvedAt' : 'rejectedAt', { level: a.level, cycle: a.review_cycle, name: a.by ?? '—', at: a.decided_at.slice(0, 16).replace('T', ' ') })}{a.note ? ` — ${a.note}` : ''}
                    </li>))}
                  {r.approvals.length === 0 && !r.prepared_by && <li className="text-fg-muted">{pt('noActivity')}</li>}
                </ol>
              </Card>
            </div>
            <Card padding="none" title={pt('payslips')} headingLevel={2} actions={<div className="flex flex-wrap items-end gap-3">
              <Input label={pt('search')} hideLabel placeholder={pt('searchEmployees')} value={search} onChange={(e) => { setSearch(e.target.value); setPage(1); }} />
              <Checkbox label={pt('issuesOnly')} checked={issuesOnly} onChange={(e) => { setIssuesOnly(e.target.checked); setPage(1); }} />
            </div>}>
              <DataTable caption={pt('payslips')} hideCaption columns={columns} rows={slips.data?.data ?? []} getRowId={(s) => s.id} loading={slips.loading}
                error={slips.error ? errorMessage(slips.error, pt('loadError')) : null} onRetry={slips.reload} empty={<EmptyState title={pt(r.prepared_at ? 'noPayslipsMatch' : 'notPrepared')} />} />
              <Pager meta={slips.data?.meta} onPage={setPage} />
            </Card>
            {decision !== null && <DecisionDialog approve={decision} subject={`${r.legal_entity.name} · ${r.period.start_date} – ${r.period.end_date} · ${money(r.net)}`} onClose={() => setDecision(null)}
              onDecide={async (note) => { await source.decide(id, decision, note); refresh(); }} />}
            {importing && <ImportDialog runId={id} onClose={() => setImporting(false)} onDone={refresh} />}
            {open && <LinesDialog runId={id} slip={open} editable={Boolean(r.can?.edit)} onClose={() => setOpen(null)} onChanged={refresh} />}
          </div>
        )}
      </ResourceView>
    </div>
  );
}

function LinesDialog({ runId, slip, editable, onClose, onChanged }: { runId: string; slip: Payslip; editable: boolean; onClose: () => void; onChanged: () => void }) {
  const { pt, t, money } = usePayrollT();
  const source = usePayrollSource();
  const { busy, run } = useAction();
  const [lines, setLines] = useState(slip.lines ?? []);
  const [adding, setAdding] = useState(false);
  const remove = (lineId: string) => run(lineId, () => source.removeLine(runId, lineId), pt('lineRemoved')).then((ok) => { if (ok) { setLines((l) => l.filter((x) => x.id !== lineId)); onChanged(); } });
  return (
    <Modal open onClose={onClose} size="lg" title={`${slip.employee.name ?? ''} · ${money(slip.net)}`} description={pt('linesHint')}
      footer={<>{editable && <Button variant="secondary" onClick={() => setAdding(true)}>{pt('addLine')}</Button>}<Button onClick={onClose}>{t('ui.close')}</Button></>}>
      {slip.issues.length > 0 && <ul className="mb-4 space-y-1 text-sm text-warning-fg">{slip.issues.map((i, n) => <li key={n}>{i.message}</li>)}</ul>}
      {lines.length === 0 ? <p className="text-sm text-fg-muted">{pt('noLines')}</p> : (
        <ul className="divide-y divide-line text-sm">{lines.map((l) => (
          <li key={l.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
            <div className="min-w-0"><span className="font-medium text-fg">{l.name}</span> <span className="text-fg-muted">({l.code} · {pt(`kind.${l.kind}`)})</span> <SourceBadge source={l.source} />
              {(l.reason || l.provider_reference) && <div className="text-xs text-fg-muted">{l.reason ?? `${l.provider ?? ''} ${l.provider_reference ?? ''}`}</div>}</div>
            <div className="flex items-center gap-2"><span className={l.kind === 'deduction' ? 'text-danger-fg' : 'text-fg'}>{l.kind === 'deduction' ? '−' : ''}{money(l.amount)}</span>
              {editable && l.source === 'manual' && <Button size="sm" variant="tertiary" loading={busy === l.id} onClick={() => remove(l.id)}>{pt('remove')}</Button>}</div>
          </li>))}</ul>)}
      {adding && <AddLineDialog runId={runId} slipId={slip.id} onClose={() => setAdding(false)} onSaved={(line) => { setLines((l) => [...l, line]); onChanged(); }} />}
    </Modal>
  );
}

function AddLineDialog({ runId, slipId, onClose, onSaved }: { runId: string; slipId: string; onClose: () => void; onSaved: (line: import('@/api/payroll').PayrollLine) => void }) {
  const { pt, t } = usePayrollT();
  const source = usePayrollSource();
  const [f, setF] = useState<LineInput>({ code: '', name: '', kind: 'deduction', amount: '', reason: '' });
  const [error, setError] = useState<unknown>(null);
  const [busy, setBusy] = useState(false);
  const fields = validationErrors(error);
  const save = async () => {
    setBusy(true);
    setError(null);
    try {
      onSaved(await source.addLine(runId, slipId, { ...f, code: f.code.toUpperCase() }));
      onClose();
    } catch (e) {
      setError(e);
    } finally {
      setBusy(false);
    }
  };
  return (
    <Modal open onClose={onClose} title={pt('addLine')} description={pt('addLineHint')}
      footer={<><Button variant="secondary" onClick={onClose}>{t('ui.cancel')}</Button><Button loading={busy} onClick={save}>{pt('save')}</Button></>}>
      <div className="grid gap-4 sm:grid-cols-2">
        <Input label={pt('code')} required value={f.code} onChange={(e) => setF({ ...f, code: e.target.value })} error={fields.code} />
        <Input label={pt('name')} required value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} error={fields.name} />
        <Select label={pt('kindLabel')} value={f.kind} onChange={(e) => setF({ ...f, kind: e.target.value as LineInput['kind'] })} options={(['earning', 'deduction'] as const).map((k) => ({ value: k, label: pt(`kind.${k}`) }))} />
        <Input label={pt('amount')} required inputMode="decimal" value={f.amount} onChange={(e) => setF({ ...f, amount: e.target.value })} error={fields.amount} />
        <TextArea label={pt('reason')} required value={f.reason} onChange={(v) => setF({ ...f, reason: v })} error={fields.reason} />
        <FormError message={formError(error, pt('actionFailed'))} />
      </div>
    </Modal>
  );
}

function ImportDialog({ runId, onClose, onDone }: { runId: string; onClose: () => void; onDone: () => void }) {
  const { pt, t } = usePayrollT();
  const source = usePayrollSource();
  const [file, setFile] = useState<File | null>(null);
  const [error, setError] = useState<unknown>(null);
  const [busy, setBusy] = useState(false);
  const submit = async () => {
    if (!file) return;
    setBusy(true);
    setError(null);
    try {
      await source.importProvider(runId, file);
      onDone();
      onClose();
    } catch (e) {
      setError(e);
    } finally {
      setBusy(false);
    }
  };
  const fields = validationErrors(error);
  return (
    <Modal open onClose={onClose} title={pt('importProvider')} description={pt('importHint')}
      footer={<><Button variant="secondary" onClick={onClose}>{t('ui.cancel')}</Button><Button loading={busy} disabled={!file} onClick={submit}>{pt('import')}</Button></>}>
      <div className="grid gap-3">
        <FileField label={pt('file')} accept=".csv,text/csv" onFile={setFile} />
        {Object.values(fields).length > 0 && <ul role="alert" className="text-sm text-danger-fg">{Object.values(fields).map((m, i) => <li key={i}>{m}</li>)}</ul>}
        <FormError message={Object.keys(fields).length ? null : formError(error, pt('actionFailed'))} />
      </div>
    </Modal>
  );
}
