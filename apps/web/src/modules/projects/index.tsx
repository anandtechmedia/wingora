import { useCallback, useState } from 'react';
import { Permission as P } from '@wingora/permissions';
import type { MessageKey } from '@wingora/localization';
import { ApiNotConnectedError } from '@/api/dashboard';
import {
  useProjectsSource, type EntryInput, type MyWeek, type Project, type ProjectInput, type ProjectQuery, type ProjectStatus, type Task, type TaskStatus,
  type Timesheet, type TimesheetStatus,
} from '@/api/projects';
import { emptyEmployeeQuery, useEmployeeSource } from '@/api/employees';
import { Badge, Button, Card, ConfirmationDialog, DataTable, Drawer, EmptyState, Input, Modal, Select, Tabs, useToast, type DataTableColumn } from '@/components/ui';
import { PageHeader } from '@/shell/page-header';
import { useI18n } from '@/i18n/i18n';
import { errorMessage, FormError, formError, ResourceView, useResource, validationErrors } from '@/modules/employees/shared';
import { Pager } from '@/modules/attendance';
import { TextArea } from '@/modules/time/shared';
import { useAction, useCan, useSave } from '@/modules/recruitment/shared';

const STATUSES: ProjectStatus[] = ['planned', 'active', 'on_hold', 'completed', 'cancelled'];
const TASK_STATUSES: TaskStatus[] = ['todo', 'in_progress', 'done'];
const projectTone = { planned: 'neutral', active: 'success', on_hold: 'warning', completed: 'accent', cancelled: 'neutral' } as const;
const taskTone = { todo: 'neutral', in_progress: 'warning', done: 'success' } as const;
const sheetTone = { draft: 'neutral', submitted: 'warning', approved: 'success', rejected: 'danger' } as const;

const isoDay = (d: Date) => d.toISOString().slice(0, 10);
const addDays = (iso: string, n: number) => { const d = new Date(`${iso}T00:00:00Z`); d.setUTCDate(d.getUTCDate() + n); return isoDay(d); };
export const mondayOf = (iso: string) => { const d = new Date(`${iso}T00:00:00Z`); return addDays(iso, -((d.getUTCDay() + 6) % 7)); };
const blank = (v: string) => (v.trim() === '' ? null : v.trim());

export function useProjectsT() {
  const { t, locale } = useI18n();
  const pt = (key: string, params?: Record<string, string | number>) => t(`projects.${key}` as MessageKey, params);
  const day = (iso: string | null) => (iso ? new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeZone: 'UTC' }).format(new Date(`${iso}T00:00:00Z`)) : '—');
  const weekday = (iso: string) => new Intl.DateTimeFormat(locale, { weekday: 'short', day: 'numeric', timeZone: 'UTC' }).format(new Date(`${iso}T00:00:00Z`));
  const hours = (minutes: number) => new Intl.NumberFormat(locale, { maximumFractionDigits: 2 }).format(minutes / 60);
  return { pt, t, day, weekday, hours };
}

function NotConnected() {
  const { pt } = useProjectsT();
  return <EmptyState title={pt('notConnectedTitle')} description={pt('notConnectedDescription')} />;
}
function SheetBadge({ status }: { status: TimesheetStatus }) {
  const { pt } = useProjectsT();
  return <Badge size="sm" dot tone={sheetTone[status]}>{pt(`sheet.${status}`)}</Badge>;
}

export default function Projects() {
  const { pt, t } = useProjectsT();
  const can = useCan();
  const tabs = [
    can(P.ProjectsView, P.ProjectsManage) && { id: 'timesheet', label: pt('myTimesheet'), content: <MyTimesheet /> },
    can(P.ProjectsView, P.ProjectsManage) && { id: 'tasks', label: pt('myTasks'), content: <MyTasks /> },
    can(P.ProjectsView, P.ProjectsManage) && { id: 'projects', label: pt('projects'), content: <ProjectList /> },
    can(P.TimesheetsApprove) && { id: 'approvals', label: pt('approvals'), content: <Approvals /> },
  ].filter((x): x is { id: string; label: string; content: React.ReactElement } => Boolean(x));
  return (
    <>
      <PageHeader title={t('nav.module.projects')} description={pt('subtitle')} />
      {tabs.length > 0 ? <Tabs label={t('nav.module.projects')} tabs={tabs} /> : <EmptyState title={pt('nothingToShow')} />}
    </>
  );
}

interface Row { key: number; project_id: string; task_id: string; hours: string[]; note: string }
let rowKey = 0;
function toRows(sheet: Timesheet): Row[] {
  const rows = new Map<string, Row>();
  for (const e of sheet.entries ?? []) {
    const id = `${e.project.id}:${e.task?.id ?? ''}`;
    const row = rows.get(id) ?? { key: ++rowKey, project_id: e.project.id, task_id: e.task?.id ?? '', hours: Array(7).fill(''), note: e.note ?? '' };
    const i = Math.round((Date.parse(`${e.work_date}T00:00:00Z`) - Date.parse(`${sheet.week_start}T00:00:00Z`)) / 86_400_000);
    const before = Math.round(Number(row.hours[i] || 0) * 60);
    row.hours[i] = String(Math.round(((before + e.minutes) / 60) * 100) / 100);
    rows.set(id, row);
  }
  return [...rows.values()];
}

function MyTimesheet() {
  const [week, setWeek] = useState(() => mondayOf(isoDay(new Date())));
  const source = useProjectsSource();
  const data = useResource(useCallback((signal: AbortSignal) => source.myWeek(week, signal), [source, week]));
  const { pt } = useProjectsT();
  if (data.error instanceof ApiNotConnectedError) return <NotConnected />;
  return (
    <Card title={pt('myTimesheet')} headingLevel={2} description={pt('timesheetHint')}
      actions={<div className="flex gap-2"><Button variant="secondary" size="sm" onClick={() => setWeek(addDays(week, -7))}>{pt('previousWeek')}</Button><Button variant="secondary" size="sm" onClick={() => setWeek(addDays(week, 7))}>{pt('nextWeek')}</Button></div>}>
      <ResourceView resource={data} empty="" isEmpty={() => false} errorLabel={pt('loadError')}>
        {(d) => <WeekGrid key={`${d.timesheet.week_start}:${d.timesheet.version}`} data={d} onSaved={data.reload} />}
      </ResourceView>
    </Card>
  );
}

function WeekGrid({ data, onSaved }: { data: MyWeek; onSaved: () => void }) {
  const { pt, day, weekday, hours } = useProjectsT();
  const source = useProjectsSource();
  const { toast } = useToast();
  const sheet = data.timesheet;
  const editable = sheet.status === 'draft' || sheet.status === 'rejected';
  const [rows, setRows] = useState<Row[]>(() => toRows(sheet));
  const { error, busy, save } = useSave();
  const fields = validationErrors(error);
  const days = Array.from({ length: 7 }, (_, i) => addDays(sheet.week_start, i));
  const minutes = (v: string) => Math.round(Number(v.replace(',', '.')) * 60);
  const badHours = (v: string) => v.trim() !== '' && !(Number.isFinite(Number(v.replace(',', '.'))) && Number(v.replace(',', '.')) >= 0);
  const invalid = rows.some((r) => r.hours.some(badHours));
  const otherError = Object.entries(fields).find(([k]) => !/^entries\.\d+\.minutes$/.test(k))?.[1];
  const entries: EntryInput[] = rows.flatMap((r) => r.hours.flatMap((h, i) => (r.project_id && h.trim() !== '' && minutes(h) > 0
    ? [{ project_id: r.project_id, task_id: r.task_id || null, work_date: days[i] as string, minutes: minutes(h), note: blank(r.note) }] : [])));
  const total = entries.reduce((sum, e) => sum + e.minutes, 0);
  const update = (key: number, patch: Partial<Row>) => setRows(rows.map((r) => (r.key === key ? { ...r, ...patch } : r)));
  const projectOptions = data.projects.map((p) => ({ value: p.id, label: `${p.code} · ${p.name}` }));
  const knownProject = (id: string) => data.projects.find((p) => p.id === id);
  const persist = () => save(async () => { await source.saveWeek(sheet.week_start, entries, sheet.version); toast({ title: pt('saved'), tone: 'success' }); onSaved(); });
  const submit = () => save(async () => {
    const saved = await source.saveWeek(sheet.week_start, entries, sheet.version);
    await source.submit(saved.id as string, saved.version);
    toast({ title: pt('submitted'), tone: 'success' });
    onSaved();
  });
  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center gap-3">
        <p className="text-sm font-medium text-fg">{pt('weekOf', { date: day(sheet.week_start) })}</p>
        <SheetBadge status={sheet.status} />
        <p className="text-sm text-fg-secondary">{pt('totalHours', { hours: hours(editable ? total : sheet.total_minutes) })}</p>
      </div>
      {sheet.status === 'rejected' && sheet.decision_note && <p role="note" className="rounded-md border border-line p-3 text-sm text-fg-secondary">{pt('rejectedNote', { note: sheet.decision_note })}</p>}
      {editable && data.projects.length === 0 && rows.length === 0 ? <EmptyState title={pt('noLoggableProjects')} /> : (
        <div className="overflow-x-auto">
          <table className="w-full min-w-[48rem] text-sm">
            <caption className="sr-only">{pt('weekOf', { date: day(sheet.week_start) })}</caption>
            <thead><tr className="text-start text-xs text-fg-muted">
              <th scope="col" className="p-2 text-start">{pt('project')}</th><th scope="col" className="p-2 text-start">{pt('task')}</th>
              {days.map((d) => <th key={d} scope="col" className="p-2 text-start">{weekday(d)}</th>)}
              {editable && <th scope="col" className="p-2"><span className="sr-only">{pt('actions')}</span></th>}
            </tr></thead>
            <tbody>
              {rows.map((r, n) => {
                const project = knownProject(r.project_id);
                const label = project ? `${project.code}` : sheet.entries?.find((e) => e.project.id === r.project_id)?.project.code ?? '';
                return (
                  <tr key={r.key} className="border-t border-line align-top">
                    <td className="p-2">{editable ? <Select label={pt('projectRow', { n: n + 1 })} hideLabel placeholder={pt('chooseProject')} value={r.project_id}
                      onChange={(e) => update(r.key, { project_id: e.target.value, task_id: '' })} options={projectOptions} /> : label}</td>
                    <td className="p-2">{editable ? <Select label={pt('taskRow', { n: n + 1 })} hideLabel value={r.task_id} disabled={!project}
                      onChange={(e) => update(r.key, { task_id: e.target.value })} options={[{ value: '', label: pt('noTask') }, ...(project?.tasks ?? []).map((x) => ({ value: x.id, label: x.title }))]} />
                      : sheet.entries?.find((e) => e.task?.id === r.task_id)?.task?.title ?? '—'}</td>
                    {days.map((d, i) => (
                      <td key={d} className="w-20 p-2">{editable ? <Input label={pt('hoursOn', { row: n + 1, day: weekday(d) })} hideLabel inputMode="decimal" size="sm" value={r.hours[i]}
                        error={badHours(r.hours[i] ?? '') ? pt('invalidHours') : fields[`entries.${entries.findIndex((e) => e.project_id === r.project_id && e.work_date === d && (e.task_id ?? '') === r.task_id)}.minutes`]}
                        onChange={(e) => update(r.key, { hours: r.hours.map((h, j) => (j === i ? e.target.value : h)) })} /> : (r.hours[i] || '—')}</td>
                    ))}
                    {editable && <td className="p-2"><Button size="sm" variant="tertiary" onClick={() => setRows(rows.filter((x) => x.key !== r.key))}>{pt('removeRow')}</Button></td>}
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}
      {editable && (
        <div className="flex flex-wrap gap-2">
          <Button variant="secondary" disabled={data.projects.length === 0} onClick={() => setRows([...rows, { key: ++rowKey, project_id: '', task_id: '', hours: Array(7).fill(''), note: '' }])}>{pt('addRow')}</Button>
          <Button variant="secondary" loading={busy} disabled={invalid} onClick={persist}>{pt('saveDraft')}</Button>
          <Button loading={busy} disabled={total === 0 || invalid} onClick={submit}>{pt('submitWeek')}</Button>
        </div>
      )}
      <FormError message={invalid ? pt('invalidHours') : error ? (otherError ?? formError(error, pt('saveFailed'))) : null} />
      {!editable && <p className="text-sm text-fg-muted">{sheet.status === 'approved' ? pt('approvedBy', { name: sheet.decided_by ?? '—' }) : pt('awaitingApproval')}</p>}
    </div>
  );
}

function MyTasks() {
  const { pt, day } = useProjectsT();
  const source = useProjectsSource();
  const [page, setPage] = useState(1);
  const tasks = useResource(useCallback((signal: AbortSignal) => source.myTasks(page, signal), [source, page]));
  const { busy, run } = useAction();
  const columns: DataTableColumn<Task>[] = [
    { id: 'title', header: pt('task'), primary: true, cell: (x) => <span className="font-medium">{x.title}</span> },
    { id: 'project', header: pt('project'), hideBelow: 'md', cell: (x) => (x.project ? `${x.project.code} · ${x.project.name}` : '—') },
    { id: 'due', header: pt('dueOn'), cell: (x) => day(x.due_on) },
    { id: 'status', header: pt('statusLabel'), cell: (x) => (
      <Select label={pt('statusFor', { task: x.title })} hideLabel size="sm" value={x.status} disabled={busy === x.id}
        onChange={(e) => void run(x.id, () => source.saveTask({ status: e.target.value as TaskStatus }, x.project?.id ?? '', x.id, x.version)).then((ok) => { if (ok) tasks.reload(); })}
        options={TASK_STATUSES.map((s) => ({ value: s, label: pt(`task.${s}`) }))} />
    ) },
  ];
  if (tasks.error instanceof ApiNotConnectedError) return <NotConnected />;
  return (
    <Card title={pt('myTasks')} headingLevel={2} padding="none" description={pt('myTasksHint')}>
      <DataTable caption={pt('myTasks')} hideCaption columns={columns} rows={tasks.data?.data ?? []} getRowId={(x) => x.id}
        loading={tasks.loading && tasks.data === undefined} error={tasks.error ? errorMessage(tasks.error, pt('loadError')) : null} onRetry={tasks.reload}
        empty={<EmptyState title={pt('noTasks')} />} />
      <Pager meta={tasks.data?.meta} onPage={setPage} />
    </Card>
  );
}

function ProjectList() {
  const { pt, day } = useProjectsT();
  const { toast } = useToast();
  const can = useCan();
  const source = useProjectsSource();
  const [q, setQ] = useState<ProjectQuery>({ page: 1, status: '' });
  const [search, setSearch] = useState('');
  const [open, setOpen] = useState<string | null>(null);
  const [creating, setCreating] = useState(false);
  const projects = useResource(useCallback((signal: AbortSignal) => source.projects(q, signal), [source, q]));
  const columns: DataTableColumn<Project>[] = [
    { id: 'name', header: pt('project'), primary: true, cell: (p) => (
      <button type="button" className="text-start font-medium text-fg-link hover:underline" onClick={() => setOpen(p.id)}><span className="text-fg-muted">{p.code}</span> {p.name}</button>
    ) },
    { id: 'client', header: pt('client'), hideBelow: 'md', cell: (p) => p.client ?? '—' },
    { id: 'status', header: pt('statusLabel'), cell: (p) => <Badge size="sm" dot tone={projectTone[p.status]}>{pt(`status.${p.status}`)}</Badge> },
    { id: 'manager', header: pt('manager'), hideBelow: 'md', cell: (p) => p.manager?.name ?? '—' },
    { id: 'ends', header: pt('endsOn'), hideBelow: 'lg', cell: (p) => day(p.ends_on) },
  ];
  return (
    <Card title={pt('projects')} headingLevel={2} padding="none" description={pt('projectsHint')} actions={can(P.ProjectsManage) ? <Button onClick={() => setCreating(true)}>{pt('newProject')}</Button> : undefined}>
      <form role="search" aria-label={pt('filters')} className="grid items-end gap-3 p-4 sm:grid-cols-3" onSubmit={(e) => { e.preventDefault(); setQ({ ...q, q: search.trim(), page: 1 }); }}>
        <Input label={pt('search')} type="search" value={search} onChange={(e) => setSearch(e.target.value)} />
        <Select label={pt('statusLabel')} value={q.status ?? ''} onChange={(e) => setQ({ ...q, status: e.target.value as ProjectQuery['status'], page: 1 })}
          options={[{ value: '', label: pt('allStatuses') }, ...STATUSES.map((s) => ({ value: s, label: pt(`status.${s}`) }))]} />
        <div><Button type="submit" variant="secondary">{pt('searchAction')}</Button></div>
      </form>
      {projects.error instanceof ApiNotConnectedError ? <div className="p-4"><NotConnected /></div> : (
        <>
          <DataTable caption={pt('projects')} hideCaption columns={columns} rows={projects.data?.data ?? []} getRowId={(p) => p.id}
            loading={projects.loading && projects.data === undefined} error={projects.error ? errorMessage(projects.error, pt('loadError')) : null} onRetry={projects.reload}
            empty={<EmptyState title={pt('noProjects')} />} />
          <Pager meta={projects.data?.meta} onPage={(page) => setQ({ ...q, page })} />
        </>
      )}
      {creating && <ProjectForm onClose={() => setCreating(false)} onSaved={(p) => { setCreating(false); toast({ title: pt('saved'), tone: 'success' }); projects.reload(); setOpen(p.id); }} />}
      {open && <ProjectDrawer id={open} onClose={() => setOpen(null)} onChanged={projects.reload} />}
    </Card>
  );
}

function EmployeePicker({ label, value, onChange, error }: { label: string; value: string; onChange: (id: string) => void; error?: string }) {
  const { pt, t } = useProjectsT();
  const employees = useEmployeeSource();
  const [search, setSearch] = useState('');
  const [term, setTerm] = useState('');
  const people = useResource(useCallback((signal: AbortSignal) => employees.list({ ...emptyEmployeeQuery, search: term, status: 'active' }, signal), [employees, term]));
  return (
    <div className="grid gap-2 sm:col-span-2">
      <div className="flex items-end gap-2">
        <div className="flex-1"><Input label={pt('findEmployee')} type="search" value={search} onChange={(e) => setSearch(e.target.value)}
          onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); setTerm(search.trim()); } }} /></div>
        <Button variant="secondary" onClick={() => setTerm(search.trim())}>{pt('searchAction')}</Button>
      </div>
      <Select label={label} placeholder={pt('chooseEmployee')} value={value} error={error} onChange={(e) => onChange(e.target.value)}
        options={(people.data?.data ?? []).map((p) => ({ value: p.id, label: `${p.preferred_name || p.first_name} ${p.last_name} · ${p.employee_number}` }))} />
      {people.error ? (
        <div role="alert" className="flex flex-wrap items-center gap-2 text-sm text-danger-fg">
          {errorMessage(people.error, pt('peopleError'))}
          <Button size="sm" variant="secondary" onClick={people.reload}>{t('ui.retry')}</Button>
        </div>
      ) : null}
    </div>
  );
}

function ProjectForm({ project, onClose, onSaved }: { project?: Project; onClose: () => void; onSaved: (p: Project) => void }) {
  const { pt, t } = useProjectsT();
  const source = useProjectsSource();
  const { error, busy, save } = useSave();
  const fields = validationErrors(error);
  const [f, setF] = useState({
    code: project?.code ?? '', name: project?.name ?? '', client: project?.client ?? '', description: project?.description ?? '', status: project?.status ?? 'active',
    starts_on: project?.starts_on ?? '', ends_on: project?.ends_on ?? '', budget_hours: project?.budget_hours ?? '', manager_id: project?.manager?.id ?? '',
  });
  const set = (patch: Partial<typeof f>) => setF({ ...f, ...patch });
  const submit = () => save(async () => {
    const input: ProjectInput = {
      code: f.code.trim(), name: f.name.trim(), client: blank(f.client), description: blank(f.description), status: f.status as ProjectStatus,
      starts_on: blank(f.starts_on), ends_on: blank(f.ends_on), budget_hours: blank(f.budget_hours), manager_id: blank(f.manager_id),
    };
    onSaved(await source.saveProject(input, project?.id, project?.version));
  });
  return (
    <Modal open onClose={onClose} size="lg" title={project ? pt('editProject') : pt('newProject')}
      footer={<><Button variant="secondary" onClick={onClose}>{t('ui.cancel')}</Button><Button loading={busy} disabled={!f.code.trim() || !f.name.trim()} onClick={submit}>{pt('save')}</Button></>}>
      <div className="grid gap-4 sm:grid-cols-2">
        <Input label={pt('code')} required maxLength={20} value={f.code} error={fields.code} onChange={(e) => set({ code: e.target.value })} />
        <Input label={pt('name')} required maxLength={120} value={f.name} error={fields.name} onChange={(e) => set({ name: e.target.value })} />
        <Input label={pt('client')} maxLength={120} value={f.client} error={fields.client} onChange={(e) => set({ client: e.target.value })} />
        <Select label={pt('statusLabel')} value={f.status} onChange={(e) => set({ status: e.target.value as ProjectStatus })} options={STATUSES.map((s) => ({ value: s, label: pt(`status.${s}`) }))} />
        <Input label={pt('startsOn')} type="date" value={f.starts_on} error={fields.starts_on} onChange={(e) => set({ starts_on: e.target.value })} />
        <Input label={pt('endsOn')} type="date" value={f.ends_on} error={fields.ends_on} onChange={(e) => set({ ends_on: e.target.value })} />
        <Input label={pt('budgetHours')} inputMode="decimal" value={f.budget_hours} error={fields.budget_hours} onChange={(e) => set({ budget_hours: e.target.value })} />
        <EmployeePicker label={pt('manager')} value={f.manager_id} error={fields.manager_id} onChange={(id) => set({ manager_id: id })} />
        <TextArea label={pt('description')} value={f.description} error={fields.description} onChange={(v) => set({ description: v })} />
        <FormError message={error ? formError(error, pt('saveFailed')) : null} />
      </div>
    </Modal>
  );
}

function ProjectDrawer({ id, onClose, onChanged }: { id: string; onClose: () => void; onChanged: () => void }) {
  const { pt } = useProjectsT();
  const source = useProjectsSource();
  const project = useResource(useCallback((signal: AbortSignal) => source.project(id, signal), [source, id]));
  const p = project.data;
  return (
    <Drawer open onClose={onClose} size="lg" title={p ? `${p.code} · ${p.name}` : pt('project')} description={p?.client ?? undefined}>
      <ResourceView resource={project} empty="" isEmpty={() => false} errorLabel={pt('loadError')}>
        {(data) => <ProjectDetail project={data} onChanged={() => { project.reload(); onChanged(); }} />}
      </ResourceView>
    </Drawer>
  );
}

function ProjectDetail({ project: p, onChanged }: { project: Project; onChanged: () => void }) {
  const { pt, day } = useProjectsT();
  const can = useCan();
  const source = useProjectsSource();
  const { toast } = useToast();
  const [dialog, setDialog] = useState<'edit' | 'member' | 'task' | null>(null);
  const [removing, setRemoving] = useState<string | null>(null);
  const [editingTask, setEditingTask] = useState<Task | null>(null);
  const [key, setKey] = useState(0);
  const [taskPage, setTaskPage] = useState(1);
  const tasks = useResource(useCallback((signal: AbortSignal) => source.tasks(p.id, taskPage, signal), [source, p.id, key, taskPage])); // eslint-disable-line react-hooks/exhaustive-deps
  const changed = () => { setDialog(null); setEditingTask(null); toast({ title: pt('saved'), tone: 'success' }); setKey((k) => k + 1); onChanged(); };
  const open = p.status !== 'completed' && p.status !== 'cancelled';
  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center gap-2"><Badge size="sm" dot tone={projectTone[p.status]}>{pt(`status.${p.status}`)}</Badge></div>
      <dl className="grid gap-4 sm:grid-cols-2">
        <div><dt className="text-xs font-medium text-fg-muted">{pt('manager')}</dt><dd className="text-sm">{p.manager?.name ?? '—'}</dd></div>
        <div><dt className="text-xs font-medium text-fg-muted">{pt('dates')}</dt><dd className="text-sm">{`${day(p.starts_on)} – ${day(p.ends_on)}`}</dd></div>
        <div><dt className="text-xs font-medium text-fg-muted">{pt('budgetHours')}</dt><dd className="text-sm">{p.budget_hours ?? '—'}</dd></div>
        {p.description && <div className="sm:col-span-2"><dt className="text-xs font-medium text-fg-muted">{pt('description')}</dt><dd className="whitespace-pre-line text-sm">{p.description}</dd></div>}
      </dl>
      {p.can_manage && (
        <div className="flex flex-wrap gap-2">
          {can(P.ProjectsManage) && <Button variant="secondary" onClick={() => setDialog('edit')}>{pt('editProject')}</Button>}
          <Button variant="secondary" onClick={() => setDialog('member')}>{pt('addMember')}</Button>
          {open && <Button onClick={() => setDialog('task')}>{pt('newTask')}</Button>}
        </div>
      )}
      <section aria-labelledby="project-members" className="space-y-2">
        <h3 id="project-members" className="text-sm font-semibold text-fg">{pt('members')}</h3>
        {(p.members ?? []).length === 0 ? <p className="text-sm text-fg-muted">{pt('noMembers')}</p> : (
          <ul className="divide-y divide-line rounded-md border border-line">
            {(p.members ?? []).map((m) => (
              <li key={m.id} className="flex items-center justify-between gap-2 p-2 text-sm">
                <span>{m.name} <span className="text-fg-muted">· {m.employee_number}</span></span>
                {p.can_manage && m.id !== p.manager?.id && <Button size="sm" variant="tertiary" onClick={() => setRemoving(m.id)}>{pt('removeMember', { name: m.name })}</Button>}
              </li>
            ))}
          </ul>
        )}
      </section>
      <section aria-labelledby="project-tasks" className="space-y-2">
        <h3 id="project-tasks" className="text-sm font-semibold text-fg">{pt('tasks')}</h3>
        <ResourceView resource={tasks} empty={pt('noTasks')} isEmpty={(d) => d.data.length === 0} errorLabel={pt('loadError')}>
          {(d) => (
            <ul className="divide-y divide-line rounded-md border border-line">
              {d.data.map((x) => (
                <li key={x.id} className="flex flex-wrap items-center justify-between gap-2 p-2 text-sm">
                  <span><span className="font-medium">{x.title}</span> <span className="text-fg-muted">· {x.assignee?.name ?? pt('unassigned')} · {day(x.due_on)}</span></span>
                  <span className="flex items-center gap-2">
                    <Badge size="sm" tone={taskTone[x.status]}>{pt(`task.${x.status}`)}</Badge>
                    {p.can_manage && open && <Button size="sm" variant="tertiary" onClick={() => setEditingTask(x)}>{pt('editTaskNamed', { task: x.title })}</Button>}
                  </span>
                </li>
              ))}
            </ul>
          )}
        </ResourceView>
        <Pager meta={tasks.data?.meta} onPage={setTaskPage} />
      </section>
      {p.can_manage && <Report project={p} />}
      {dialog === 'edit' && <ProjectForm project={p} onClose={() => setDialog(null)} onSaved={changed} />}
      {dialog === 'member' && <MemberDialog project={p} onClose={() => setDialog(null)} onDone={changed} />}
      {(dialog === 'task' || editingTask) && <TaskForm project={p} task={editingTask ?? undefined} onClose={() => { setDialog(null); setEditingTask(null); }} onSaved={changed} />}
      <ConfirmationDialog open={removing !== null} title={pt('removeMemberTitle')} description={pt('removeMemberDescription')} confirmLabel={pt('remove')}
        onCancel={() => setRemoving(null)}
        onConfirm={async () => {
          try {
            if (removing) await source.removeMember(p.id, removing);
            setRemoving(null);
            changed();
          } catch (e) {
            setRemoving(null);
            toast({ title: errorMessage(e, pt('saveFailed')), tone: 'danger' });
          }
        }} />
    </div>
  );
}

function MemberDialog({ project, onClose, onDone }: { project: Project; onClose: () => void; onDone: () => void }) {
  const { pt, t } = useProjectsT();
  const source = useProjectsSource();
  const [employeeId, setEmployeeId] = useState('');
  const { error, busy, save } = useSave();
  const fields = validationErrors(error);
  return (
    <Modal open onClose={onClose} size="md" title={pt('addMember')}
      footer={<><Button variant="secondary" onClick={onClose}>{t('ui.cancel')}</Button><Button loading={busy} disabled={!employeeId} onClick={() => save(async () => { await source.addMember(project.id, employeeId); onDone(); })}>{pt('addMember')}</Button></>}>
      <div className="grid gap-4 sm:grid-cols-2">
        <EmployeePicker label={pt('employee')} value={employeeId} error={fields.employee_id} onChange={setEmployeeId} />
        <FormError message={error ? formError(error, pt('saveFailed')) : null} />
      </div>
    </Modal>
  );
}

function TaskForm({ project, task, onClose, onSaved }: { project: Project; task?: Task; onClose: () => void; onSaved: () => void }) {
  const { pt, t } = useProjectsT();
  const source = useProjectsSource();
  const { error, busy, save } = useSave();
  const fields = validationErrors(error);
  const [f, setF] = useState({ title: task?.title ?? '', description: task?.description ?? '', assignee_id: task?.assignee?.id ?? '', due_on: task?.due_on ?? '', estimate_hours: task?.estimate_hours ?? '', status: task?.status ?? 'todo' });
  const set = (patch: Partial<typeof f>) => setF({ ...f, ...patch });
  const submit = () => save(async () => {
    await source.saveTask({ title: f.title.trim(), description: blank(f.description), assignee_id: blank(f.assignee_id), due_on: blank(f.due_on), estimate_hours: blank(f.estimate_hours), ...(task ? { status: f.status as TaskStatus } : {}) }, project.id, task?.id, task?.version);
    onSaved();
  });
  return (
    <Modal open onClose={onClose} size="md" title={task ? pt('editTask') : pt('newTask')}
      footer={<><Button variant="secondary" onClick={onClose}>{t('ui.cancel')}</Button><Button loading={busy} disabled={!f.title.trim()} onClick={submit}>{pt('save')}</Button></>}>
      <div className="grid gap-4 sm:grid-cols-2">
        <div className="sm:col-span-2"><Input label={pt('taskTitle')} required maxLength={160} value={f.title} error={fields.title} onChange={(e) => set({ title: e.target.value })} /></div>
        <Select label={pt('assignee')} value={f.assignee_id} error={fields.assignee_id} onChange={(e) => set({ assignee_id: e.target.value })}
          options={[{ value: '', label: pt('unassigned') }, ...(project.members ?? []).map((m) => ({ value: m.id, label: m.name }))]} />
        {task && <Select label={pt('statusLabel')} value={f.status} onChange={(e) => set({ status: e.target.value as TaskStatus })} options={TASK_STATUSES.map((s) => ({ value: s, label: pt(`task.${s}`) }))} />}
        <Input label={pt('dueOn')} type="date" value={f.due_on} error={fields.due_on} onChange={(e) => set({ due_on: e.target.value })} />
        <Input label={pt('estimateHours')} inputMode="decimal" value={f.estimate_hours} error={fields.estimate_hours} onChange={(e) => set({ estimate_hours: e.target.value })} />
        <TextArea label={pt('description')} value={f.description} error={fields.description} onChange={(v) => set({ description: v })} />
        <FormError message={error ? formError(error, pt('saveFailed')) : null} />
      </div>
    </Modal>
  );
}

function Report({ project }: { project: Project }) {
  const { pt, hours, day } = useProjectsT();
  const source = useProjectsSource();
  const [range, setRange] = useState({ from: '', to: '' });
  const [applied, setApplied] = useState({ from: '', to: '' });
  const report = useResource(useCallback((signal: AbortSignal) => source.report(project.id, applied, signal), [source, project.id, applied]));
  return (
    <section aria-labelledby="project-report" className="space-y-3">
      <h3 id="project-report" className="text-sm font-semibold text-fg">{pt('report')}</h3>
      <form className="flex flex-wrap items-end gap-2" onSubmit={(e) => { e.preventDefault(); setApplied(range); }}>
        <Input label={pt('from')} type="date" size="sm" value={range.from} onChange={(e) => setRange({ ...range, from: e.target.value })} />
        <Input label={pt('to')} type="date" size="sm" value={range.to} onChange={(e) => setRange({ ...range, to: e.target.value })} />
        <Button type="submit" size="sm" variant="secondary">{pt('apply')}</Button>
      </form>
      <ResourceView resource={report} empty="" isEmpty={() => false} errorLabel={pt('loadError')}>
        {(r) => (
          <div className="space-y-3 text-sm">
            <dl className="grid gap-3 sm:grid-cols-3">
              <div><dt className="text-xs text-fg-muted">{pt('approvedHours')}</dt><dd className="text-lg font-semibold">{hours(r.totals.approved_minutes)}</dd></div>
              <div><dt className="text-xs text-fg-muted">{pt('pendingHours')}</dt><dd className="text-lg font-semibold">{hours(r.totals.pending_minutes)}</dd></div>
              <div><dt className="text-xs text-fg-muted">{pt('budgetUsed')}</dt><dd className="text-lg font-semibold">{r.totals.budget_used_percent === null ? '—' : `${r.totals.budget_used_percent}%`}</dd></div>
            </dl>
            {r.by_employee.length === 0 ? <p className="text-fg-muted">{pt('noHours')}</p> : (
              <table className="w-full">
                <caption className="sr-only">{pt('byPerson')}</caption>
                <thead><tr className="text-xs text-fg-muted"><th scope="col" className="p-1 text-start">{pt('byPerson')}</th><th scope="col" className="p-1 text-end">{pt('approvedHours')}</th><th scope="col" className="p-1 text-end">{pt('pendingHours')}</th></tr></thead>
                <tbody>
                  {r.by_employee.map((x) => <tr key={x.employee?.id ?? 'none'} className="border-t border-line"><td className="p-1">{x.employee?.name ?? '—'}</td><td className="p-1 text-end">{hours(x.approved_minutes)}</td><td className="p-1 text-end">{hours(x.pending_minutes)}</td></tr>)}
                  {r.by_task.map((x) => <tr key={`t-${x.task?.id ?? 'none'}`} className="border-t border-line text-fg-secondary"><td className="p-1">{x.task?.title ?? pt('noTask')}</td><td className="p-1 text-end">{hours(x.approved_minutes)}</td><td className="p-1 text-end">{hours(x.pending_minutes)}</td></tr>)}
                  {r.by_week.map((x) => <tr key={`w-${x.week_start}`} className="border-t border-line text-fg-muted"><td className="p-1">{pt('weekOf', { date: day(x.week_start) })}</td><td className="p-1 text-end">{hours(x.approved_minutes)}</td><td className="p-1 text-end">{hours(x.pending_minutes)}</td></tr>)}
                </tbody>
              </table>
            )}
          </div>
        )}
      </ResourceView>
    </section>
  );
}

function Approvals() {
  const { pt, day, hours } = useProjectsT();
  const source = useProjectsSource();
  const [page, setPage] = useState(1);
  const list = useResource(useCallback((signal: AbortSignal) => source.approvals(page, signal), [source, page]));
  const [open, setOpen] = useState<string | null>(null);
  const columns: DataTableColumn<Timesheet>[] = [
    { id: 'employee', header: pt('employee'), primary: true, cell: (s) => (
      <button type="button" className="text-start font-medium text-fg-link hover:underline" onClick={() => setOpen(s.id)}>{s.employee?.name ?? '—'}</button>
    ) },
    { id: 'week', header: pt('week'), cell: (s) => day(s.week_start) },
    { id: 'hours', header: pt('hours'), cell: (s) => hours(s.total_minutes) },
    { id: 'status', header: pt('statusLabel'), cell: (s) => <SheetBadge status={s.status} /> },
  ];
  if (list.error instanceof ApiNotConnectedError) return <NotConnected />;
  return (
    <Card title={pt('approvals')} headingLevel={2} padding="none" description={pt('approvalsHint')}>
      <DataTable caption={pt('approvals')} hideCaption columns={columns} rows={list.data?.data ?? []} getRowId={(s) => s.id ?? s.week_start}
        loading={list.loading && list.data === undefined} error={list.error ? errorMessage(list.error, pt('loadError')) : null} onRetry={list.reload}
        empty={<EmptyState title={pt('noApprovals')} />} />
      <Pager meta={list.data?.meta} onPage={setPage} />
      {open && <ReviewDialog id={open} onClose={() => setOpen(null)} onDone={() => { setOpen(null); list.reload(); }} />}
    </Card>
  );
}

function ReviewDialog({ id, onClose, onDone }: { id: string; onClose: () => void; onDone: () => void }) {
  const { pt, t, day, hours } = useProjectsT();
  const source = useProjectsSource();
  const { toast } = useToast();
  const sheet = useResource(useCallback((signal: AbortSignal) => source.timesheet(id, signal), [source, id]));
  const [note, setNote] = useState('');
  const { error, busy, save } = useSave();
  const fields = validationErrors(error);
  const decide = (decision: 'approve' | 'reject') => save(async () => {
    await source.decide(id, decision, sheet.data?.version ?? 0, blank(note));
    toast({ title: decision === 'approve' ? pt('approved') : pt('rejected'), tone: 'success' });
    onDone();
  });
  const pending = sheet.data?.status === 'submitted';
  return (
    <Modal open onClose={onClose} size="lg" title={sheet.data ? pt('reviewTitle', { name: sheet.data.employee?.name ?? '', date: day(sheet.data.week_start) }) : pt('timesheet')}
      footer={<><Button variant="secondary" onClick={onClose}>{t('ui.cancel')}</Button>{pending && <><Button variant="secondary" loading={busy} onClick={() => decide('reject')}>{pt('reject')}</Button><Button loading={busy} onClick={() => decide('approve')}>{pt('approve')}</Button></>}</>}>
      <ResourceView resource={sheet} empty="" isEmpty={() => false} errorLabel={pt('loadError')}>
        {(s) => (
          <div className="space-y-4">
            <p className="text-sm">{pt('totalHours', { hours: hours(s.total_minutes) })}</p>
            <ul className="divide-y divide-line rounded-md border border-line text-sm">
              {(s.entries ?? []).map((e, i) => (
                <li key={i} className="flex justify-between gap-2 p-2"><span>{day(e.work_date)} · {e.project.code}{e.task ? ` · ${e.task.title}` : ''}</span><span>{hours(e.minutes)}</span></li>
              ))}
            </ul>
            {pending && <TextArea label={pt('decisionNote')} value={note} error={fields.note} onChange={setNote} />}
            <FormError message={error ? formError(error, pt('saveFailed')) : null} />
          </div>
        )}
      </ResourceView>
    </Modal>
  );
}
