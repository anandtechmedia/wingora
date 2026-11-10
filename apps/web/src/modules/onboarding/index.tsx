import { useCallback, useState } from 'react';
import { Permission as P } from '@wingora/permissions';
import { ApiNotConnectedError } from '@/api/dashboard';
import { useRecruitmentSource, type Assignee, type OnboardingStage, type OnboardingTask, type Plan, type Template, type TemplateTaskInput } from '@/api/recruitment';
import { Button, Card, DataTable, Drawer, EmptyState, Input, Modal, Select, Tabs, type DataTableColumn } from '@/components/ui';
import { PageHeader } from '@/shell/page-header';
import { errorMessage, FormError, formError, ResourceView, useResource, validationErrors } from '@/modules/employees/shared';
import { Pager } from '@/modules/attendance';
import { TextArea } from '@/modules/time/shared';
import { FileField } from '@/modules/payroll/shared';
import { RecruitmentNotConnected, TaskStatusBadge, todayIso, useAction, useCan, useRecruitmentT, useSave } from '@/modules/recruitment/shared';

const STAGES: OnboardingStage[] = ['pre_boarding', 'first_day', 'first_week', 'first_month'];
const ASSIGNEES: Assignee[] = ['hr', 'manager', 'employee'];

export default function Onboarding() {
  const { ot, t } = useRecruitmentT();
  const can = useCan();
  const tabs = [
    can(P.OnboardingTasksSelf, P.OnboardingManage) && { id: 'mine', label: ot('myTasks'), content: <MyTasks /> },
    can(P.OnboardingView) && { id: 'plans', label: ot('plans'), content: <Plans /> },
    can(P.OnboardingConfigure) && { id: 'templates', label: ot('templates'), content: <Templates /> },
  ].filter((x): x is { id: string; label: string; content: React.ReactElement } => Boolean(x));
  return (
    <>
      <PageHeader title={t('nav.module.onboarding')} description={ot('subtitle')} />
      {tabs.length > 0 ? <Tabs label={t('nav.module.onboarding')} tabs={tabs} /> : <EmptyState title={ot('nothingToShow')} />}
    </>
  );
}

function ProgressBar({ percent, label }: { percent: number; label: string }) {
  return (
    <div className="flex min-w-32 items-center gap-2">
      <div role="progressbar" aria-label={label} aria-valuenow={percent} aria-valuemin={0} aria-valuemax={100} className="h-2 flex-1 overflow-hidden rounded-full bg-line">
        <div className="h-full bg-primary" style={{ width: `${percent}%` }} />
      </div>
      <span className="text-xs tabular-nums text-fg-muted">{percent}%</span>
    </div>
  );
}

function MyTasks() {
  const { ot } = useRecruitmentT();
  const source = useRecruitmentSource();
  const tasks = useResource(useCallback((signal: AbortSignal) => source.myTasks(signal), [source]));
  return (
    <Card title={ot('myTasks')} headingLevel={2} description={ot('myTasksHint')}>
      {tasks.error instanceof ApiNotConnectedError ? <RecruitmentNotConnected /> : (
        <ResourceView resource={tasks} empty={ot('noTasks')} isEmpty={(d) => d.length === 0}>
          {(list) => <ul className="divide-y divide-line">{list.map((task) => <li key={task.id} className="py-3"><TaskRow task={task} onChanged={tasks.reload} showEmployee /></li>)}</ul>}
        </ResourceView>
      )}
    </Card>
  );
}

/** One task with the actions its viewer may take: complete, upload the requested document, or (HR) review and skip. */
function TaskRow({ task, onChanged, showEmployee = false }: { task: OnboardingTask; onChanged: () => void; showEmployee?: boolean }) {
  const { ot, t } = useRecruitmentT();
  const source = useRecruitmentSource();
  const { busy, run } = useAction();
  const [file, setFile] = useState<File | null>(null);
  const [dialog, setDialog] = useState<'return' | 'skip' | null>(null);
  const [note, setNote] = useState('');
  const open = !['completed', 'skipped'].includes(task.status);
  const act = (key: string, fn: () => Promise<unknown>, done: string) => run(key, fn, done).then((ok) => { if (ok) { setDialog(null); setNote(''); onChanged(); } });
  return (
    <div className="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
      <div className="min-w-0">
        <div className="font-medium text-fg">{task.title}</div>
        <div className="mt-1 flex flex-wrap items-center gap-2 text-xs text-fg-muted">
          {showEmployee && task.employee && <span>{task.employee.name}</span>}
          <span>{ot(`stage.${task.stage}`)}</span>
          <span>{ot('dueOn', { date: task.due_on })}</span>
          <span>{task.assignee_user?.name ?? ot(`assignee.${task.assignee}`)}</span>
          <TaskStatusBadge status={task.status} overdue={task.overdue} />
        </div>
        {task.description && <p className="mt-1 text-sm text-fg-muted">{task.description}</p>}
        {task.review_note && <p className="mt-1 text-sm text-danger-fg">{ot('returnedNote', { note: task.review_note })}</p>}
        {task.document && <p className="mt-1 text-sm text-fg">{ot('documentRequested', { name: task.document.name })}{task.document.file && ` · ${task.document.file.name}`}</p>}
      </div>
      <div className="flex shrink-0 flex-wrap items-center gap-2">
        {task.document?.file && task.can.review && <Button size="sm" variant="tertiary" loading={busy === `dl${task.id}`} onClick={() => run(`dl${task.id}`, () => source.downloadTaskDocument(task.id))}>{ot('viewDocument')}</Button>}
        {open && task.can.work && task.document && task.status !== 'submitted' && <>
          <FileField label={ot('uploadDocument')} accept=".pdf,.png,.jpg,.jpeg,.docx" onFile={setFile} />
          <Button size="sm" disabled={!file} loading={busy === `up${task.id}`} onClick={() => file && act(`up${task.id}`, () => source.uploadTaskDocument(task.id, file), ot('uploaded'))}>{ot('submitDocument')}</Button>
        </>}
        {open && task.can.work && !task.document && <Button size="sm" loading={busy === `c${task.id}`} onClick={() => act(`c${task.id}`, () => source.completeTask(task.id, null), ot('completed'))}>{ot('markDone')}</Button>}
        {task.status === 'submitted' && task.can.review && <>
          <Button size="sm" loading={busy === `a${task.id}`} onClick={() => act(`a${task.id}`, () => source.reviewTask(task.id, 'accept', null), ot('accepted'))}>{ot('accept')}</Button>
          <Button size="sm" variant="secondary" onClick={() => setDialog('return')}>{ot('return')}</Button>
        </>}
        {open && task.can.review && <Button size="sm" variant="tertiary" onClick={() => setDialog('skip')}>{ot('skip')}</Button>}
      </div>
      {dialog && <Modal open size="sm" onClose={() => setDialog(null)} title={ot(dialog === 'return' ? 'returnTitle' : 'skipTitle')} description={task.title}
        footer={<><Button variant="secondary" onClick={() => setDialog(null)}>{t('ui.cancel')}</Button>
          <Button disabled={note.trim().length < 3} loading={busy === `d${task.id}`} onClick={() => act(`d${task.id}`, () => (dialog === 'return' ? source.reviewTask(task.id, 'return', note.trim()) : source.skipTask(task.id, note.trim())), ot('saved'))}>{ot(dialog)}</Button></>}>
        <TextArea label={ot('note')} required value={note} onChange={setNote} />
      </Modal>}
    </div>
  );
}

function Plans() {
  const { ot } = useRecruitmentT();
  const source = useRecruitmentSource();
  const can = useCan();
  const [status, setStatus] = useState('in_progress');
  const [q, setQ] = useState('');
  const [page, setPage] = useState(1);
  const [selected, setSelected] = useState<string | null>(null);
  const [starting, setStarting] = useState(false);
  const plans = useResource(useCallback((signal: AbortSignal) => source.plans({ status, q, page }, signal), [source, status, q, page]));
  const columns: DataTableColumn<Plan>[] = [
    { id: 'employee', header: ot('employee'), primary: true, cell: (p) => <div><div className="font-medium text-fg">{p.employee.name}</div><div className="text-xs text-fg-muted">{[p.employee.job_title, p.employee.department].filter(Boolean).join(' · ')}</div></div> },
    { id: 'start', header: ot('startDate'), cell: (p) => p.start_date },
    { id: 'manager', header: ot('manager'), cell: (p) => p.manager?.name ?? '—' },
    { id: 'progress', header: ot('progress'), cell: (p) => <div><ProgressBar percent={p.progress.percent} label={ot('progressOf', { name: p.employee.name })} />
      {p.progress.overdue > 0 && <span className="text-xs text-danger-fg">{ot('overdueCount', { count: p.progress.overdue })}</span>}</div> },
    { id: 'status', header: ot('statusLabel'), cell: (p) => ot(`planStatus.${p.status}`) },
    { id: 'open', header: ot('actions'), cell: (p) => <Button size="sm" variant="tertiary" onClick={() => setSelected(p.id)}>{ot('open')}</Button> },
  ];
  return (
    <Card padding="none" title={ot('plans')} headingLevel={2} actions={<div className="flex flex-wrap items-end gap-2">
      <Input label={ot('search')} hideLabel placeholder={ot('search')} value={q} onChange={(e) => { setQ(e.target.value); setPage(1); }} />
      <Select label={ot('statusLabel')} hideLabel value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }} placeholder={ot('allStatuses')}
        options={(['in_progress', 'completed', 'cancelled'] as const).map((s) => ({ value: s, label: ot(`planStatus.${s}`) }))} />
      {can(P.OnboardingManage) && <Button size="sm" onClick={() => setStarting(true)}>{ot('startOnboarding')}</Button>}
    </div>}>
      {plans.error instanceof ApiNotConnectedError ? <RecruitmentNotConnected /> : <>
        <DataTable caption={ot('plans')} hideCaption columns={columns} rows={plans.data?.data ?? []} getRowId={(p) => p.id} loading={plans.loading}
          error={plans.error ? errorMessage(plans.error, ot('loadError')) : null} onRetry={plans.reload} empty={<EmptyState title={ot('noPlans')} />} />
        <Pager meta={plans.data?.meta} onPage={setPage} />
      </>}
      {selected && <PlanDrawer id={selected} onClose={() => { setSelected(null); plans.reload(); }} />}
      {starting && <StartDialog onClose={() => setStarting(false)} onStarted={(id) => { setStarting(false); setSelected(id); }} />}
    </Card>
  );
}

function PlanDrawer({ id, onClose }: { id: string; onClose: () => void }) {
  const { ot } = useRecruitmentT();
  const source = useRecruitmentSource();
  const { busy, run } = useAction();
  const plan = useResource(useCallback((signal: AbortSignal) => source.plan(id, signal), [source, id]));
  const p = plan.data;
  return (
    <Drawer open onClose={onClose} size="lg" title={p ? p.employee.name : ot('plan')} description={p ? ot('planHint', { date: p.start_date, template: p.template?.name ?? '—' }) : undefined}
      footer={p?.can.manage && p.status === 'in_progress' ? <Button variant="danger" loading={busy === 'cancel'} onClick={() => run('cancel', () => source.cancelPlan(id), ot('cancelled')).then((ok) => ok && plan.reload())}>{ot('cancelPlan')}</Button> : undefined}>
      <ResourceView resource={plan} empty={ot('noTasks')}>
        {(d) => <div className="space-y-6">
          <ProgressBar percent={d.progress.percent} label={ot('progressOf', { name: d.employee.name })} />
          <p className="text-sm text-fg-muted">{ot('progressSummary', { done: d.progress.done, total: d.progress.total })}</p>
          {STAGES.map((stage) => {
            const tasks = (d.tasks ?? []).filter((x) => x.stage === stage);
            return tasks.length === 0 ? null : (
              <section key={stage}>
                <h3 className="text-sm font-semibold text-fg">{ot(`stage.${stage}`)}</h3>
                <ul className="mt-2 divide-y divide-line">{tasks.map((x) => <li key={x.id} className="py-3"><TaskRow task={x} onChanged={plan.reload} /></li>)}</ul>
              </section>
            );
          })}
        </div>}
      </ResourceView>
    </Drawer>
  );
}

function StartDialog({ onClose, onStarted }: { onClose: () => void; onStarted: (id: string) => void }) {
  const { ot, t } = useRecruitmentT();
  const source = useRecruitmentSource();
  const templates = useResource(useCallback((signal: AbortSignal) => source.templates(signal), [source]));
  const [input, setInput] = useState({ employee_id: '', template_id: '', start_date: todayIso() });
  const { error, busy, save } = useSave();
  const fields = validationErrors(error);
  return (
    <Modal open onClose={onClose} title={ot('startOnboarding')} description={ot('startHint')}
      footer={<><Button variant="secondary" onClick={onClose}>{t('ui.cancel')}</Button>
        <Button loading={busy} disabled={!input.employee_id || !input.template_id} onClick={() => save(async () => onStarted((await source.startPlan(input)).id))}>{ot('start')}</Button></>}>
      <div className="grid gap-4 sm:grid-cols-2">
        <Input label={ot('employeeId')} required hint={ot('employeeIdHint')} value={input.employee_id} onChange={(e) => setInput({ ...input, employee_id: e.target.value.trim() })} error={fields.employee_id} />
        <Input label={ot('startDate')} type="date" required value={input.start_date} onChange={(e) => setInput({ ...input, start_date: e.target.value })} error={fields.start_date} />
        <Select label={ot('template')} required value={input.template_id} onChange={(e) => setInput({ ...input, template_id: e.target.value })} placeholder={ot('chooseTemplate')} error={fields.template_id}
          options={(templates.data ?? []).filter((x) => x.status === 'active').map((x) => ({ value: x.id, label: x.legal_entity ? `${x.name} (${x.legal_entity.name})` : x.name }))} />
        <FormError message={Object.keys(fields).length ? null : error ? formError(error, ot('actionFailed')) : null} />
      </div>
    </Modal>
  );
}

function Templates() {
  const { ot } = useRecruitmentT();
  const source = useRecruitmentSource();
  const [creating, setCreating] = useState(false);
  const templates = useResource(useCallback((signal: AbortSignal) => source.templates(signal), [source]));
  return (
    <div className="space-y-4">
      <div className="flex justify-end"><Button size="sm" onClick={() => setCreating(true)}>{ot('newTemplate')}</Button></div>
      {templates.error instanceof ApiNotConnectedError ? <RecruitmentNotConnected /> : (
        <ResourceView resource={templates} empty={ot('noTemplates')} isEmpty={(d) => d.length === 0}>
          {(list) => <div className="space-y-4">{list.map((tpl) => <TemplateCard key={tpl.id} template={tpl} onChanged={templates.reload} />)}</div>}
        </ResourceView>
      )}
      {creating && <NewTemplateDialog onClose={() => setCreating(false)} onCreated={() => { setCreating(false); templates.reload(); }} />}
    </div>
  );
}

function TemplateCard({ template, onChanged }: { template: Template; onChanged: () => void }) {
  const { ot } = useRecruitmentT();
  const source = useRecruitmentSource();
  const { busy, run } = useAction();
  const [adding, setAdding] = useState(false);
  return (
    <Card title={template.name} headingLevel={2} description={template.legal_entity ? `${template.legal_entity.name} · ${template.legal_entity.country_code}` : ot('allEntities')}
      actions={<Button size="sm" variant="secondary" onClick={() => setAdding(true)}>{ot('addTask')}</Button>}>
      {template.tasks.length === 0 ? <p className="text-sm text-fg-muted">{ot('noTemplateTasks')}</p> : (
        <ul className="divide-y divide-line">
          {template.tasks.map((x) => (
            <li key={x.id} className="flex flex-col gap-1 py-2 sm:flex-row sm:items-center sm:justify-between">
              <div className="min-w-0"><div className="text-sm font-medium text-fg">{x.title}</div>
                <div className="text-xs text-fg-muted">{ot(`stage.${x.stage}`)} · {x.assignee_user?.name ?? ot(`assignee.${x.assignee}`)} · {ot('offsetDays', { days: x.due_offset_days })}{x.document_name && ` · ${ot('documentRequested', { name: x.document_name })}`}</div></div>
              <Button size="sm" variant="tertiary" loading={busy === x.id} onClick={() => run(x.id, () => source.removeTemplateTask(x.id), ot('removed')).then((ok) => ok && onChanged())}>{ot('remove')}</Button>
            </li>
          ))}
        </ul>
      )}
      {adding && <TaskDialog template={template} onClose={() => setAdding(false)} onSaved={() => { setAdding(false); onChanged(); }} />}
    </Card>
  );
}

function TaskDialog({ template, onClose, onSaved }: { template: Template; onClose: () => void; onSaved: () => void }) {
  const { ot, t } = useRecruitmentT();
  const source = useRecruitmentSource();
  const entity = template.legal_entity?.id;
  const docs = useResource(useCallback((signal: AbortSignal) => (entity ? source.documentRequirements(entity, signal) : Promise.resolve([])), [source, entity]));
  const [input, setInput] = useState<TemplateTaskInput>({ stage: 'pre_boarding', title: '', assignee: 'hr', due_offset_days: 0, document_key: null, document_name: null });
  const { error, busy, save } = useSave();
  const fields = validationErrors(error);
  const pickDoc = (key: string) => {
    const doc = docs.data?.find((d) => d.key === key);
    setInput({ ...input, document_key: doc?.key ?? null, document_name: doc?.name ?? null, title: input.title || doc?.name || '', assignee: doc ? 'employee' : input.assignee });
  };
  return (
    <Modal open onClose={onClose} title={ot('addTask')} description={template.name}
      footer={<><Button variant="secondary" onClick={onClose}>{t('ui.cancel')}</Button>
        <Button loading={busy} disabled={!input.title.trim()} onClick={() => save(async () => { await source.addTemplateTask(template.id, input); onSaved(); })}>{ot('save')}</Button></>}>
      <div className="grid gap-4 sm:grid-cols-2">
        <Select label={ot('countryDocument')} value={input.document_key ?? ''} onChange={(e) => pickDoc(e.target.value)} placeholder={ot('noDocument')}
          hint={entity ? ot('countryDocumentHint') : ot('countryDocumentNeedsEntity')} options={(docs.data ?? []).map((d) => ({ value: d.key, label: d.name }))} />
        <Input label={ot('taskTitle')} required value={input.title} onChange={(e) => setInput({ ...input, title: e.target.value })} error={fields.title} />
        <Select label={ot('stageLabel')} value={input.stage} onChange={(e) => setInput({ ...input, stage: e.target.value as OnboardingStage })} options={STAGES.map((s) => ({ value: s, label: ot(`stage.${s}`) }))} />
        <Select label={ot('assigneeLabel')} value={input.assignee} onChange={(e) => setInput({ ...input, assignee: e.target.value as Assignee })} options={ASSIGNEES.map((a) => ({ value: a, label: ot(`assignee.${a}`) }))} />
        <Input label={ot('dueOffset')} type="number" hint={ot('dueOffsetHint')} value={String(input.due_offset_days)} onChange={(e) => setInput({ ...input, due_offset_days: Number(e.target.value) })} error={fields.due_offset_days} />
        <FormError message={Object.keys(fields).length ? null : error ? formError(error, ot('actionFailed')) : null} />
      </div>
    </Modal>
  );
}

function NewTemplateDialog({ onClose, onCreated }: { onClose: () => void; onCreated: () => void }) {
  const { ot, t } = useRecruitmentT();
  const source = useRecruitmentSource();
  const entities = useResource(useCallback((signal: AbortSignal) => source.legalEntities(signal), [source]));
  const [name, setName] = useState('');
  const [entity, setEntity] = useState('');
  const { error, busy, save } = useSave();
  const fields = validationErrors(error);
  return (
    <Modal open onClose={onClose} title={ot('newTemplate')}
      footer={<><Button variant="secondary" onClick={onClose}>{t('ui.cancel')}</Button>
        <Button loading={busy} disabled={!name.trim()} onClick={() => save(async () => { await source.createTemplate({ name: name.trim(), legal_entity_id: entity || null, description: null }); onCreated(); })}>{ot('create')}</Button></>}>
      <div className="grid gap-4 sm:grid-cols-2">
        <Input label={ot('templateName')} required value={name} onChange={(e) => setName(e.target.value)} error={fields.name} />
        <Select label={ot('legalEntity')} value={entity} onChange={(e) => setEntity(e.target.value)} placeholder={ot('allEntities')} hint={ot('legalEntityHint')}
          options={(entities.data ?? []).map((e) => ({ value: e.id, label: `${e.name} (${e.country_code})` }))} />
        <FormError message={Object.keys(fields).length ? null : error ? formError(error, ot('actionFailed')) : null} />
      </div>
    </Modal>
  );
}
