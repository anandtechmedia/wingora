import { useCallback, useState } from 'react';
import { Permission as P } from '@wingora/permissions';
import type { MessageKey } from '@wingora/localization';
import { ApiNotConnectedError } from '@/api/dashboard';
import {
  useDocumentsSource, type Delivery, type DocumentRequest, type DocumentTemplate, type FieldType, type RequestQuery, type RequestStatus,
  type TemplateField, type TemplateInput,
} from '@/api/documents';
import { Badge, Button, Card, Checkbox, DataTable, Drawer, EmptyState, Input, Modal, Select, Tabs, useToast, type DataTableColumn } from '@/components/ui';
import { PageHeader } from '@/shell/page-header';
import { useI18n } from '@/i18n/i18n';
import { errorMessage, FormError, formError, ResourceView, useResource, validationErrors } from '@/modules/employees/shared';
import { Pager } from '@/modules/attendance';
import { TextArea } from '@/modules/time/shared';
import { FileField } from '@/modules/payroll/shared';
import { useAction, useCan, useSave } from '@/modules/recruitment/shared';

const STATUSES: RequestStatus[] = ['pending_manager', 'pending_hr', 'approved', 'rejected', 'delivered', 'cancelled'];
const FIELD_TYPES: FieldType[] = ['text', 'textarea', 'date'];
const statusTone = { pending_manager: 'warning', pending_hr: 'warning', approved: 'accent', rejected: 'danger', delivered: 'success', cancelled: 'neutral' } as const;
const PLACEHOLDERS = ['employee.name', 'employee.first_name', 'employee.last_name', 'employee.number', 'employee.job_title', 'employee.department', 'employee.designation',
  'employee.hire_date', 'employee.employment_type', 'entity.legal_name', 'entity.country', 'today', 'request.number'];
const ACCEPT = '.pdf,.docx';

export function useDocumentsT() {
  const { t, locale } = useI18n();
  const dt = (key: string, params?: Record<string, string | number>) => t(`documents.${key}` as MessageKey, params);
  const when = (iso: string) => new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(iso));
  return { dt, t, when };
}

function StatusBadge({ status }: { status: RequestStatus }) {
  const { dt } = useDocumentsT();
  return <Badge size="sm" dot tone={statusTone[status]}>{dt(`status.${status}`)}</Badge>;
}
function NotConnected() {
  const { dt } = useDocumentsT();
  return <EmptyState title={dt('notConnectedTitle')} description={dt('notConnectedDescription')} />;
}
function FieldAlert({ message }: { message?: string }) {
  return message ? <p role="alert" className="text-sm text-danger-fg sm:col-span-2">{message}</p> : null;
}

export default function Documents() {
  const { dt, t } = useDocumentsT();
  const can = useCan();
  const tabs = [
    can(P.DocumentsRequest) && { id: 'mine', label: dt('myRequests'), content: <RequestList scope="mine" /> },
    can(P.DocumentsApprove, P.DocumentsManage, P.DocumentsSalary) && { id: 'approvals', label: dt('approvals'), content: <RequestList scope="approvals" /> },
    can(P.DocumentsConfigure) && { id: 'templates', label: dt('templates'), content: <Templates /> },
  ].filter((x): x is { id: string; label: string; content: React.ReactElement } => Boolean(x));
  return (
    <>
      <PageHeader title={t('nav.module.documents')} description={dt('subtitle')} />
      {tabs.length > 0 ? <Tabs label={t('nav.module.documents')} tabs={tabs} /> : <EmptyState title={dt('nothingToShow')} />}
    </>
  );
}

function RequestList({ scope }: { scope: 'mine' | 'approvals' }) {
  const { dt, when } = useDocumentsT();
  const { toast } = useToast();
  const can = useCan();
  const source = useDocumentsSource();
  const [q, setQ] = useState<RequestQuery>({ page: 1, scope, status: scope === 'mine' ? '' : 'open' });
  const [search, setSearch] = useState('');
  const [open, setOpen] = useState<string | null>(null);
  const [creating, setCreating] = useState(false);
  const requests = useResource(useCallback((signal: AbortSignal) => source.requests(q, signal), [source, q]));
  const set = (patch: Partial<RequestQuery>) => setQ({ ...q, ...patch, page: 1 });
  const columns = ([
    { id: 'request', header: dt('request'), primary: true, cell: (r) => (
      <button type="button" className="text-start font-medium text-fg-link hover:underline" onClick={() => setOpen(r.id)}>
        <span className="text-fg-muted tabular-nums">#{r.number}</span> {r.template.name}
      </button>
    ) },
    scope === 'approvals' && { id: 'employee', header: dt('employee'), cell: (r) => r.employee?.name ?? '—' },
    { id: 'status', header: dt('statusLabel'), cell: (r) => <StatusBadge status={r.status} /> },
    { id: 'requested', header: dt('requested'), hideBelow: 'md', cell: (r) => (r.created_at ? when(r.created_at) : '—') },
  ] as (DataTableColumn<DocumentRequest> | false)[]).filter((c): c is DataTableColumn<DocumentRequest> => Boolean(c));
  const title = dt(scope === 'mine' ? 'myRequests' : 'approvals');

  return (
    <Card title={title} headingLevel={2} padding="none" description={dt(scope === 'mine' ? 'myRequestsHint' : 'approvalsHint')}
      actions={scope === 'mine' && <Button onClick={() => setCreating(true)}>{dt('newRequest')}</Button>}>
      <form role="search" aria-label={dt('filters')} className="grid items-end gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4" onSubmit={(e) => { e.preventDefault(); set({ q: search.trim() }); }}>
        {scope === 'approvals' && <Input label={dt('search')} type="search" value={search} onChange={(e) => setSearch(e.target.value)} />}
        <Select label={dt('statusLabel')} value={q.status ?? ''} onChange={(e) => set({ status: e.target.value as RequestQuery['status'] })}
          options={[{ value: 'open', label: dt('openOnly') }, { value: '', label: dt('allStatuses') }, ...STATUSES.map((s) => ({ value: s, label: dt(`status.${s}`) }))]} />
        {scope === 'approvals' && can(P.DocumentsManage) && <Select label={dt('show')} value={q.scope} onChange={(e) => set({ scope: e.target.value as RequestQuery['scope'] })}
          options={[{ value: 'approvals', label: dt('scopeApprovals') }, { value: 'all', label: dt('scopeAll') }]} />}
        {scope === 'approvals' && <div><Button type="submit" variant="secondary">{dt('searchAction')}</Button></div>}
      </form>
      {requests.error instanceof ApiNotConnectedError ? <div className="p-4"><NotConnected /></div> : (
        <>
          <DataTable caption={title} hideCaption columns={columns} rows={requests.data?.data ?? []} getRowId={(r) => r.id}
            loading={requests.loading && requests.data === undefined} error={requests.error ? errorMessage(requests.error, dt('loadError')) : null} onRetry={requests.reload}
            empty={<EmptyState title={dt(scope === 'mine' ? 'noRequests' : 'noApprovals')} />} />
          <Pager meta={requests.data?.meta} onPage={(page) => setQ({ ...q, page })} />
        </>
      )}
      {creating && <NewRequestDialog onClose={() => setCreating(false)} onCreated={(r) => { setCreating(false); toast({ title: dt('submitted', { number: r.number }), tone: 'success' }); requests.reload(); setOpen(r.id); }} />}
      {open && <RequestDrawer id={open} onClose={() => setOpen(null)} onChanged={requests.reload} />}
    </Card>
  );
}

function AnswerInput({ field, value, onChange, error }: { field: TemplateField; value: string; onChange: (v: string) => void; error?: string }) {
  if (field.type === 'textarea') return <TextArea label={field.label} required={field.required} value={value} error={error} onChange={onChange} />;
  return (
    <div className="sm:col-span-2">
      <Input label={field.label} required={field.required} type={field.type === 'date' ? 'date' : 'text'} maxLength={500} value={value} error={error} onChange={(e) => onChange(e.target.value)} />
    </div>
  );
}

function NewRequestDialog({ onClose, onCreated }: { onClose: () => void; onCreated: (r: DocumentRequest) => void }) {
  const { dt, t } = useDocumentsT();
  const source = useDocumentsSource();
  const templates = useResource(useCallback((signal: AbortSignal) => source.templates(signal), [source]));
  const [templateId, setTemplateId] = useState('');
  const [answers, setAnswers] = useState<Record<string, string>>({});
  const { error, busy, save } = useSave();
  const fields = validationErrors(error);
  const active = (templates.data ?? []).filter((x) => x.active);
  const selected = active.find((x) => x.id === templateId);
  const ready = selected !== undefined && selected.fields.every((f) => !f.required || (answers[f.key] ?? '').trim() !== '');
  const submit = () => save(async () => {
    if (!selected) return;
    const filled = Object.fromEntries(selected.fields.map((f) => [f.key, (answers[f.key] ?? '').trim()]).filter(([, v]) => v !== ''));
    onCreated(await source.submit(selected.id, filled));
  });
  return (
    <Modal open onClose={onClose} size="lg" title={dt('newRequest')} description={dt('newRequestHint')}
      footer={<><Button variant="secondary" onClick={onClose}>{t('ui.cancel')}</Button><Button loading={busy} disabled={!ready} onClick={submit}>{dt('submit')}</Button></>}>
      <div className="grid gap-4 sm:grid-cols-2">
        <div className="sm:col-span-2">
          <Select label={dt('documentType')} required value={templateId} placeholder={dt('chooseTemplate')} error={fields.template_id}
            onChange={(e) => { setTemplateId(e.target.value); setAnswers({}); }} options={active.map((x) => ({ value: x.id, label: x.name }))} />
        </div>
        {templates.error != null && <FieldAlert message={errorMessage(templates.error, dt('loadError'))} />}
        {templates.data && active.length === 0 && <p className="text-sm text-fg-muted sm:col-span-2">{dt('noTemplates')}</p>}
        {selected?.description && <p className="whitespace-pre-line text-sm text-fg-muted sm:col-span-2">{selected.description}</p>}
        {selected?.requires_manager && <p className="text-sm text-fg-muted sm:col-span-2">{dt('managerStepNotice')}</p>}
        {selected?.includes_salary && <p className="text-sm text-fg-muted sm:col-span-2">{dt('salaryNotice')}</p>}
        {selected?.fields.map((f) => (
          <AnswerInput key={f.key} field={f} value={answers[f.key] ?? ''} error={fields[`answers.${f.key}`]} onChange={(v) => setAnswers({ ...answers, [f.key]: v })} />
        ))}
        <FormError message={error ? formError(error, dt('submitFailed')) : null} />
      </div>
    </Modal>
  );
}

function RequestDrawer({ id, onClose, onChanged }: { id: string; onClose: () => void; onChanged: () => void }) {
  const { dt } = useDocumentsT();
  const source = useDocumentsSource();
  const request = useResource(useCallback((signal: AbortSignal) => source.request(id, signal), [source, id]));
  const r = request.data;
  return (
    <Drawer open onClose={onClose} size="lg" title={r ? `#${r.number} · ${r.template.name}` : dt('request')} description={r?.employee ? `${r.employee.name} · ${r.employee.employee_number}` : undefined}>
      <ResourceView resource={request} empty="" isEmpty={() => false} errorLabel={dt('loadError')}>
        {(data) => <RequestDetail request={data} onChanged={() => { request.reload(); onChanged(); }} />}
      </ResourceView>
    </Drawer>
  );
}

function Meta({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <dt className="text-xs font-medium text-fg-muted">{label}</dt>
      <dd className="whitespace-pre-line text-sm text-fg">{value}</dd>
    </div>
  );
}

function RequestDetail({ request: r, onChanged }: { request: DocumentRequest; onChanged: () => void }) {
  const { dt, when } = useDocumentsT();
  const source = useDocumentsSource();
  const { busy, run } = useAction();
  const [rejecting, setRejecting] = useState(false);
  const [reason, setReason] = useState('');
  const [salary, setSalary] = useState('');
  const [file, setFile] = useState<File | null>(null);
  const act = (key: string, fn: () => Promise<unknown>, done: string) => run(key, fn, done).then((ok) => { if (ok) setRejecting(false); onChanged(); });
  const step = (d: { by: string | null; at: string } | null) => (d ? `${d.by ?? '—'}, ${when(d.at)}` : dt('pendingStep'));
  const upload = r.template.delivery === 'upload';
  const deliverReady = upload ? file !== null : !r.template.includes_salary || salary.trim() !== '';
  const answered = r.template.fields.filter((f) => (r.answers[f.key] ?? '') !== '');

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center gap-2">
        <StatusBadge status={r.status} />
        <Badge size="sm" variant="outline" tone="neutral">{dt(`delivery.${r.template.delivery}`)}</Badge>
        {r.template.includes_salary && <Badge size="sm" variant="outline" tone="info">{dt('salaryBadge')}</Badge>}
      </div>
      <dl className="grid gap-4 sm:grid-cols-2">
        <Meta label={dt('requestedBy')} value={r.requester.name} />
        <Meta label={dt('requested')} value={r.created_at ? when(r.created_at) : '—'} />
        {r.manager_step && <Meta label={dt('managerStep')} value={step(r.manager_decision)} />}
        <Meta label={dt('finalStep')} value={step(r.decision)} />
        {r.delivered_at && <Meta label={dt('status.delivered')} value={when(r.delivered_at)} />}
        {r.rejection_reason && <Meta label={dt('rejectionReason')} value={r.rejection_reason} />}
      </dl>
      <section aria-labelledby="doc-answers" className="space-y-2">
        <h3 id="doc-answers" className="text-sm font-semibold text-fg">{dt('answers')}</h3>
        {answered.length === 0 ? <p className="text-sm text-fg-muted">{dt('noAnswers')}</p> : (
          <dl className="grid gap-3 sm:grid-cols-2">{answered.map((f) => <Meta key={f.key} label={f.label} value={r.answers[f.key] ?? ''} />)}</dl>
        )}
      </section>

      {r.can.download && r.document && (
        <Button variant="secondary" loading={busy === 'download'} onClick={() => run('download', () => source.download(r.id))}>{dt('download')}</Button>
      )}

      {r.can.decide && (
        <section aria-label={dt('decision')} className="space-y-3 rounded-md border border-line p-4">
          {!rejecting ? (
            <div className="flex flex-wrap gap-2">
              {r.status !== 'approved' && <Button loading={busy === 'approve'} onClick={() => act('approve', () => source.decide(r.id, 'approve', null, r.version), dt('approvedToast'))}>{dt('approve')}</Button>}
              <Button variant="secondary" onClick={() => setRejecting(true)}>{dt('reject')}</Button>
            </div>
          ) : (
            <div className="grid gap-3">
              <TextArea label={dt('rejectionReason')} required value={reason} onChange={setReason} />
              <div className="flex flex-wrap gap-2">
                <Button variant="danger" disabled={reason.trim().length < 3} loading={busy === 'reject'}
                  onClick={() => act('reject', () => source.decide(r.id, 'reject', reason.trim(), r.version), dt('rejectedToast'))}>{dt('confirmReject')}</Button>
                <Button variant="secondary" onClick={() => setRejecting(false)}>{dt('back')}</Button>
              </div>
            </div>
          )}
        </section>
      )}

      {r.can.deliver && (
        <section aria-label={dt('deliver')} className="grid gap-3 rounded-md border border-line p-4">
          <p className="text-sm text-fg-muted">{dt(upload ? 'deliverUploadHint' : 'deliverGeneratedHint')}</p>
          {upload ? <FileField label={dt('file')} accept={ACCEPT} onFile={setFile} /> : r.template.includes_salary && (
            <Input label={dt('salary')} required hint={dt('salaryHint')} maxLength={100} value={salary} onChange={(e) => setSalary(e.target.value)} />
          )}
          <div>
            <Button disabled={!deliverReady} loading={busy === 'deliver'}
              onClick={() => act('deliver', () => source.deliver(r.id, r.version, upload ? { file } : { salary: salary.trim() || undefined }), dt('deliveredToast'))}>{dt('deliver')}</Button>
          </div>
        </section>
      )}

      {r.can.cancel && (
        <Button variant="secondary" loading={busy === 'cancel'} onClick={() => act('cancel', () => source.cancel(r.id, r.version), dt('cancelledToast'))}>{dt('cancel')}</Button>
      )}
    </div>
  );
}

function Templates() {
  const { dt } = useDocumentsT();
  const source = useDocumentsSource();
  const [editing, setEditing] = useState<DocumentTemplate | 'new' | null>(null);
  const templates = useResource(useCallback((signal: AbortSignal) => source.templates(signal), [source]));
  const columns: DataTableColumn<DocumentTemplate>[] = [
    { id: 'name', header: dt('name'), primary: true, cell: (x) => (
      <button type="button" className="text-start font-medium text-fg-link hover:underline" aria-label={dt('editNamed', { name: x.name })} onClick={() => setEditing(x)}>{x.name}</button>
    ) },
    { id: 'delivery', header: dt('deliveryLabel'), hideBelow: 'md', cell: (x) => dt(`delivery.${x.delivery}`) },
    { id: 'steps', header: dt('steps'), hideBelow: 'lg', cell: (x) => dt(x.requires_manager ? 'stepsManager' : 'stepsHr') },
    { id: 'flags', header: dt('statusLabel'), cell: (x) => (
      <span className="inline-flex flex-wrap gap-1">
        <Badge size="sm" dot tone={x.active ? 'success' : 'neutral'}>{dt(x.active ? 'active' : 'inactive')}</Badge>
        {x.includes_salary && <Badge size="sm" variant="outline" tone="info">{dt('salaryBadge')}</Badge>}
        {x.legal_entity && <Badge size="sm" variant="outline" tone="neutral">{dt('entityOnly', { name: x.legal_entity.name })}</Badge>}
      </span>
    ) },
  ];
  return (
    <Card title={dt('templates')} headingLevel={2} padding="none" description={dt('templatesHint')} actions={<Button onClick={() => setEditing('new')}>{dt('newTemplate')}</Button>}>
      {templates.error instanceof ApiNotConnectedError ? <div className="p-4"><NotConnected /></div> : (
        <DataTable caption={dt('templates')} hideCaption columns={columns} rows={templates.data ?? []} getRowId={(x) => x.id}
          loading={templates.loading && templates.data === undefined} error={templates.error ? errorMessage(templates.error, dt('loadError')) : null} onRetry={templates.reload}
          empty={<EmptyState title={dt('noTemplatesYet')} />} />
      )}
      {editing && <TemplateDialog template={editing === 'new' ? null : editing} onClose={() => setEditing(null)} onSaved={() => { setEditing(null); templates.reload(); }} />}
    </Card>
  );
}

const blankField = (): TemplateField => ({ key: '', label: '', type: 'text', required: false });

function TemplateDialog({ template, onClose, onSaved }: { template: DocumentTemplate | null; onClose: () => void; onSaved: () => void }) {
  const { dt, t } = useDocumentsT();
  const { toast } = useToast();
  const source = useDocumentsSource();
  const [input, setInput] = useState<TemplateInput>({
    name: template?.name ?? '', description: template?.description ?? '', delivery: template?.delivery ?? 'generated', body: template?.body ?? '',
    fields: template?.fields ?? [], requires_manager: template?.requires_manager ?? false, includes_salary: template?.includes_salary ?? false, active: template?.active ?? true,
  });
  const { error, busy, save } = useSave();
  const errs = validationErrors(error);
  const generated = input.delivery === 'generated';
  const setField = (i: number, patch: Partial<TemplateField>) => setInput({ ...input, fields: input.fields.map((f, j) => (j === i ? { ...f, ...patch } : f)) });
  const tokens = [...(template?.placeholders ?? PLACEHOLDERS), ...input.fields.filter((f) => f.key).map((f) => `answers.${f.key}`), ...(input.includes_salary ? ['salary'] : [])];
  const submit = () => save(async () => {
    await source.saveTemplate({ ...input, description: input.description?.trim() || null, body: generated ? input.body : null, fields: input.fields.map((f) => ({ ...f, key: f.key.trim(), label: f.label.trim() })) }, template?.id);
    toast({ title: dt('saved'), tone: 'success' });
    onSaved();
  });
  return (
    <Modal open onClose={onClose} size="lg" title={template ? dt('editTemplate') : dt('newTemplate')}
      footer={<><Button variant="secondary" onClick={onClose}>{t('ui.cancel')}</Button><Button loading={busy} disabled={input.name.trim() === ''} onClick={submit}>{dt('save')}</Button></>}>
      <div className="grid gap-4 sm:grid-cols-2">
        <Input label={dt('name')} required maxLength={150} value={input.name} error={errs.name} onChange={(e) => setInput({ ...input, name: e.target.value })} />
        <Select label={dt('deliveryLabel')} value={input.delivery} error={errs.delivery} onChange={(e) => setInput({ ...input, delivery: e.target.value as Delivery })}
          options={(['generated', 'upload'] as Delivery[]).map((d) => ({ value: d, label: dt(`delivery.${d}`) }))} />
        <TextArea label={dt('descriptionOptional')} value={input.description ?? ''} error={errs.description} onChange={(description) => setInput({ ...input, description })} />
        <fieldset className="space-y-3 sm:col-span-2">
          <legend className="text-sm font-medium text-fg">{dt('fieldsLabel')}</legend>
          {input.fields.map((f, i) => (
            <div key={i} className="grid items-end gap-2 rounded-md border border-line p-3 sm:grid-cols-[1fr_1.5fr_1fr_auto]">
              <Input label={dt('fieldKey')} required maxLength={40} value={f.key} error={errs[`fields.${i}.key`]} onChange={(e) => setField(i, { key: e.target.value })} />
              <Input label={dt('fieldLabel')} required maxLength={150} value={f.label} error={errs[`fields.${i}.label`]} onChange={(e) => setField(i, { label: e.target.value })} />
              <Select label={dt('fieldType')} value={f.type} onChange={(e) => setField(i, { type: e.target.value as FieldType })}
                options={FIELD_TYPES.map((x) => ({ value: x, label: dt(`fieldType.${x}`) }))} />
              <div className="flex items-center gap-3 pb-2">
                <Checkbox label={dt('fieldRequired')} checked={f.required} onChange={(e) => setField(i, { required: e.target.checked })} />
                <Button size="sm" variant="tertiary" aria-label={dt('removeField', { n: i + 1 })} onClick={() => setInput({ ...input, fields: input.fields.filter((_, j) => j !== i) })}>×</Button>
              </div>
            </div>
          ))}
          {input.fields.length < 10 && <Button size="sm" variant="secondary" onClick={() => setInput({ ...input, fields: [...input.fields, blankField()] })}>{dt('addField')}</Button>}
          <FieldAlert message={errs.fields} />
        </fieldset>
        {generated && (
          <>
            <TextArea label={dt('body')} required value={input.body ?? ''} error={errs.body} onChange={(body) => setInput({ ...input, body })} />
            <div className="sm:col-span-2">
              <p className="text-xs text-fg-muted">{dt('bodyHint')}</p>
              <ul className="mt-1 flex flex-wrap gap-1" aria-label={dt('placeholders')}>
                {tokens.map((x) => <li key={x}><code className="rounded bg-surface-subtle px-1 text-xs">{`{{${x}}}`}</code></li>)}
              </ul>
            </div>
          </>
        )}
        <div className="space-y-3 sm:col-span-2">
          <Checkbox label={dt('requiresManager')} description={dt('requiresManagerHint')} checked={input.requires_manager} onChange={(e) => setInput({ ...input, requires_manager: e.target.checked })} />
          <Checkbox label={dt('includesSalary')} description={dt('includesSalaryHint')} checked={input.includes_salary} onChange={(e) => setInput({ ...input, includes_salary: e.target.checked })} />
          <Checkbox label={dt('activeLabel')} description={dt('activeHint')} checked={input.active} onChange={(e) => setInput({ ...input, active: e.target.checked })} />
          <FieldAlert message={errs.includes_salary} />
        </div>
        <FormError message={error ? formError(error, dt('saveFailed')) : null} />
      </div>
    </Modal>
  );
}
