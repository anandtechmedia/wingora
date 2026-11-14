import { useCallback, useState } from 'react';
import { Permission as P } from '@wingora/permissions';
import { usePerformanceSource, type KeyResultInput, type ListQuery, type Objective, type ObjectiveKind, type ObjectiveStatus, type Person } from '@/api/performance';
import { Badge, Button, DataTable, Drawer, Input, Modal, Select, type DataTableColumn } from '@/components/ui';
import { FormError, formError, ResourceView, useResource, validationErrors } from '@/modules/employees/shared';
import { Pager } from '@/modules/attendance';
import { TextArea } from '@/modules/time/shared';
import { useAction, useCan, useSave } from '@/modules/recruitment/shared';
import { ProgressBar, Section, StatusBadge, usePerfT } from './shared';

const STATUSES: ObjectiveStatus[] = ['draft', 'active', 'completed', 'cancelled'];

export function ScopeSelect({ value, onChange }: { value: ListQuery['scope']; onChange: (s: ListQuery['scope']) => void }) {
  const { pt } = usePerfT();
  const can = useCan();
  if (!can(P.PerformanceReview, P.PerformanceManage)) return null;
  return <Select label={pt('show')} value={value} onChange={(e) => onChange(e.target.value as ListQuery['scope'])}
    options={[{ value: 'mine', label: pt('scope.mine') }, { value: 'team', label: pt('scope.team') }, { value: 'all', label: pt('scope.all') }]} />;
}

export function Objectives() {
  const { pt, date } = usePerfT();
  const source = usePerformanceSource();
  const [q, setQ] = useState<ListQuery>({ page: 1, scope: 'mine', status: 'active' });
  const [search, setSearch] = useState('');
  const [open, setOpen] = useState<string | null>(null);
  const [creating, setCreating] = useState(false);
  const query = { ...q, q: search.trim() };
  const objectives = useResource(useCallback((s: AbortSignal) => source.objectives(query, s), [source, q.page, q.scope, q.status, query.q])); // eslint-disable-line react-hooks/exhaustive-deps
  const set = (patch: Partial<ListQuery>) => setQ((x) => ({ ...x, ...patch, page: 1 }));
  const columns: DataTableColumn<Objective>[] = [
    { id: 'title', header: pt('objective'), primary: true, cell: (o) => (
      <button type="button" className="text-start font-medium text-fg-link hover:underline" onClick={() => setOpen(o.id)}>{o.title}</button>
    ) },
    { id: 'owner', header: pt('owner'), hideBelow: 'md', cell: (o) => o.employee?.name ?? '—' },
    { id: 'kind', header: pt('kindLabel'), hideBelow: 'lg', cell: (o) => <Badge size="sm" variant="outline" tone="neutral">{pt(`kind.${o.kind}`)}</Badge> },
    { id: 'progress', header: pt('progress'), cell: (o) => <ProgressBar value={o.progress} label={pt('progressOf', { name: o.title })} /> },
    { id: 'due', header: pt('due'), hideBelow: 'md', cell: (o) => date(o.due_on) },
    { id: 'status', header: pt('statusLabel'), cell: (o) => <StatusBadge status={o.status} /> },
  ];
  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-end gap-3">
        <ScopeSelect value={q.scope} onChange={(scope) => set({ scope })} />
        <Select label={pt('statusLabel')} value={q.status ?? ''} onChange={(e) => set({ status: e.target.value })}
          options={[{ value: '', label: pt('allStatuses') }, ...STATUSES.map((s) => ({ value: s, label: pt(`status.${s}`) }))]} />
        <Input label={pt('search')} type="search" value={search} onChange={(e) => { setSearch(e.target.value); setQ((x) => ({ ...x, page: 1 })); }} />
        <div className="ms-auto"><Button onClick={() => setCreating(true)}>{pt('newObjective')}</Button></div>
      </div>
      <ResourceView resource={objectives} empty={pt('noObjectives')} isEmpty={(p) => p.data.length === 0}>
        {(p) => (
          <div className="rounded-lg border border-line bg-surface">
            <DataTable caption={pt('objectives')} hideCaption columns={columns} rows={p.data} getRowId={(o) => o.id} />
            <Pager meta={p.meta} onPage={(page) => setQ((x) => ({ ...x, page }))} />
          </div>
        )}
      </ResourceView>
      {creating && <ObjectiveDialog onClose={() => setCreating(false)} onSaved={(o) => { setCreating(false); objectives.reload(); setOpen(o.id); }} />}
      {open && <ObjectiveDrawer id={open} onClose={() => setOpen(null)} onChanged={objectives.reload} />}
    </div>
  );
}

function ObjectiveDialog({ onClose, onSaved }: { onClose: () => void; onSaved: (o: Objective) => void }) {
  const { pt, t } = usePerfT();
  const source = usePerformanceSource();
  const can = useCan();
  const manages = can(P.PerformanceReview, P.PerformanceManage);
  const people = useResource(useCallback((s: AbortSignal) => (manages ? source.people('', s) : Promise.resolve([] as Person[])), [source, manages]));
  const [kind, setKind] = useState<ObjectiveKind>('okr');
  const [title, setTitle] = useState('');
  const [description, setDescription] = useState('');
  const [due, setDue] = useState('');
  const [owner, setOwner] = useState('');
  const [krs, setKrs] = useState<KeyResultInput[]>([{ title: '', start_value: 0, target_value: 100, unit: '%' }]);
  const { error, busy, save } = useSave();
  const fields = validationErrors(error);
  const submit = () => save(async () => onSaved(await source.saveObjective({
    kind, title, description: description || null, due_on: due || null, ...(owner ? { employee_id: owner } : {}),
    ...(kind === 'okr' ? { key_results: krs } : {}),
  })));
  const setKr = (i: number, patch: Partial<KeyResultInput>) => setKrs((rows) => rows.map((r, j) => (j === i ? { ...r, ...patch } : r)));
  return (
    <Modal open onClose={onClose} size="lg" title={pt('newObjective')}
      footer={<><Button variant="secondary" onClick={onClose}>{t('ui.cancel')}</Button><Button loading={busy} disabled={!title.trim()} onClick={submit}>{pt('create')}</Button></>}>
      <div className="grid gap-4 sm:grid-cols-2">
        <FormError message={formError(error, pt('saveError'))} />
        <Select label={pt('kindLabel')} value={kind} onChange={(e) => setKind(e.target.value as ObjectiveKind)} options={(['okr', 'objective'] as const).map((k) => ({ value: k, label: pt(`kind.${k}`) }))} />
        {manages && <Select label={pt('owner')} value={owner} onChange={(e) => setOwner(e.target.value)} error={fields.employee_id}
          options={[{ value: '', label: pt('myself') }, ...(people.data ?? []).map((p) => ({ value: p.id, label: p.name }))]} />}
        <div className="sm:col-span-2"><Input label={pt('titleLabel')} required maxLength={200} value={title} error={fields.title} onChange={(e) => setTitle(e.target.value)} /></div>
        <TextArea label={pt('description')} value={description} onChange={setDescription} error={fields.description} />
        <Input label={pt('due')} type="date" value={due} error={fields.due_on} onChange={(e) => setDue(e.target.value)} />
        {kind === 'okr' && (
          <fieldset className="space-y-3 sm:col-span-2">
            <legend className="text-sm font-medium text-fg">{pt('keyResults')}</legend>
            {fields.key_results && <p role="alert" className="text-sm text-danger-fg">{fields.key_results}</p>}
            {krs.map((kr, i) => (
              <div key={i} className="grid gap-2 sm:grid-cols-[2fr_1fr_1fr_1fr_auto] sm:items-end">
                <Input label={pt('krTitle', { n: i + 1 })} required maxLength={200} value={kr.title} error={fields[`key_results.${i}.title`]} onChange={(e) => setKr(i, { title: e.target.value })} />
                <Input label={pt('start')} type="number" value={String(kr.start_value ?? 0)} onChange={(e) => setKr(i, { start_value: Number(e.target.value) })} />
                <Input label={pt('target')} type="number" value={String(kr.target_value)} error={fields[`key_results.${i}.target_value`]} onChange={(e) => setKr(i, { target_value: Number(e.target.value) })} />
                <Input label={pt('unit')} maxLength={20} value={kr.unit ?? ''} onChange={(e) => setKr(i, { unit: e.target.value })} />
                <Button variant="tertiary" disabled={krs.length === 1} onClick={() => setKrs((rows) => rows.filter((_, j) => j !== i))} aria-label={pt('removeKr', { n: i + 1 })}>{pt('remove')}</Button>
              </div>
            ))}
            {krs.length < 10 && <Button variant="secondary" size="sm" onClick={() => setKrs((rows) => [...rows, { title: '', start_value: 0, target_value: 100, unit: '%' }])}>{pt('addKr')}</Button>}
          </fieldset>
        )}
      </div>
    </Modal>
  );
}

function ObjectiveDrawer({ id, onClose, onChanged }: { id: string; onClose: () => void; onChanged: () => void }) {
  const { pt, when } = usePerfT();
  const source = usePerformanceSource();
  const objective = useResource(useCallback((s: AbortSignal) => source.objective(id, s), [source, id]));
  const { busy, run } = useAction();
  const [kr, setKr] = useState('');
  const [value, setValue] = useState('');
  const [note, setNote] = useState('');
  const o = objective.data;
  const refresh = () => { objective.reload(); onChanged(); };
  const checkIn = async () => {
    if (!o) return;
    const hasKrs = (o.key_results ?? []).length > 0;
    const ok = await run('checkin', () => source.checkIn(o.id, hasKrs ? { key_result_id: kr || o.key_results![0]!.id, value: Number(value), note: note || null } : { progress: Number(value), note: note || null }), pt('checkedIn'));
    if (ok) { setValue(''); setNote(''); refresh(); }
  };
  const setStatus = async (status: ObjectiveStatus) => {
    if (o && await run(status, () => source.saveObjective({ version: o.version, status }, o.id), pt('saved'))) refresh();
  };
  return (
    <Drawer open onClose={onClose} size="lg" title={o?.title ?? pt('objective')} description={o?.employee?.name}>
      <ResourceView resource={objective} empty="">
        {(o) => (
          <div className="space-y-6">
            <div className="flex flex-wrap items-center gap-3"><StatusBadge status={o.status} /><ProgressBar value={o.progress} label={pt('progressOf', { name: o.title })} />
              {o.parent && <span className="text-sm text-fg-muted">{pt('alignedTo', { title: o.parent.title })}</span>}</div>
            {o.description && <p className="whitespace-pre-line text-sm text-fg">{o.description}</p>}
            {(o.key_results ?? []).length > 0 && (
              <Section title={pt('keyResults')}>
                <ul className="space-y-2">{o.key_results!.map((k) => (
                  <li key={k.id} className="flex flex-wrap items-center justify-between gap-2 rounded-md border border-line p-3 text-sm">
                    <span className="font-medium text-fg">{k.title}</span>
                    <span className="text-fg-muted">{pt('krValues', { current: k.current_value, target: k.target_value, unit: k.unit ?? '' })}</span>
                    <ProgressBar value={k.progress} label={pt('progressOf', { name: k.title })} />
                  </li>
                ))}</ul>
              </Section>
            )}
            {o.can_edit && o.status === 'active' && (
              <Section title={pt('checkIn')}>
                <div className="grid gap-3 sm:grid-cols-2">
                  {(o.key_results ?? []).length > 0 && <Select label={pt('keyResult')} value={kr || o.key_results![0]!.id} onChange={(e) => setKr(e.target.value)} options={o.key_results!.map((k) => ({ value: k.id, label: k.title }))} />}
                  <Input label={(o.key_results ?? []).length > 0 ? pt('currentValue') : pt('progressPercent')} type="number" required value={value} onChange={(e) => setValue(e.target.value)} />
                  <TextArea label={pt('note')} value={note} onChange={setNote} />
                  <div className="flex gap-2 sm:col-span-2">
                    <Button loading={busy === 'checkin'} disabled={value === ''} onClick={checkIn}>{pt('saveCheckIn')}</Button>
                    <Button variant="secondary" loading={busy === 'completed'} onClick={() => setStatus('completed')}>{pt('markComplete')}</Button>
                    <Button variant="tertiary" loading={busy === 'cancelled'} onClick={() => setStatus('cancelled')}>{pt('cancelObjective')}</Button>
                  </div>
                </div>
              </Section>
            )}
            {o.can_edit && o.status !== 'active' && <Button variant="secondary" loading={busy === 'active'} onClick={() => setStatus('active')}>{pt('reopen')}</Button>}
            <Section title={pt('checkIns')}>
              {(o.check_ins ?? []).length === 0 ? <p className="text-sm text-fg-muted">{pt('noCheckIns')}</p> : (
                <ul className="space-y-2">{o.check_ins!.map((c) => (
                  <li key={c.id} className="rounded-md border border-line p-3 text-sm">
                    <div className="flex flex-wrap justify-between gap-2 text-fg-muted"><span>{c.author ?? '—'}{c.key_result ? ` · ${c.key_result}` : ''}</span><span>{c.created_at ? when(c.created_at) : ''}</span></div>
                    <p className="text-fg">{c.value !== null ? `${c.value} · ` : ''}{c.progress}%</p>
                    {c.note && <p className="whitespace-pre-line text-fg">{c.note}</p>}
                  </li>
                ))}</ul>
              )}
            </Section>
          </div>
        )}
      </ResourceView>
    </Drawer>
  );
}
