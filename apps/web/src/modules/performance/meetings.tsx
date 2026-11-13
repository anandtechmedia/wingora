import { useCallback, useState } from 'react';
import { Permission as P } from '@wingora/permissions';
import { usePerformanceSource, type ActionItem, type ListQuery, type Meeting, type MeetingStatus, type Person } from '@/api/performance';
import { Button, Checkbox, DataTable, Drawer, Input, Modal, Select, type DataTableColumn } from '@/components/ui';
import { FormError, formError, ResourceView, useResource, validationErrors } from '@/modules/employees/shared';
import { Pager } from '@/modules/attendance';
import { TextArea } from '@/modules/time/shared';
import { useCan, useSave } from '@/modules/recruitment/shared';
import { ScopeSelect } from './objectives';
import { Section, StatusBadge, usePerfT } from './shared';

const STATUSES: MeetingStatus[] = ['scheduled', 'completed', 'cancelled'];

export function Meetings() {
  const { pt, when } = usePerfT();
  const source = usePerformanceSource();
  const [q, setQ] = useState<ListQuery>({ page: 1, scope: 'mine' });
  const [open, setOpen] = useState<string | null>(null);
  const [creating, setCreating] = useState(false);
  const meetings = useResource(useCallback((s: AbortSignal) => source.meetings(q, s), [source, q]));
  const columns: DataTableColumn<Meeting>[] = [
    { id: 'when', header: pt('when'), primary: true, cell: (m) => (
      <button type="button" className="text-start font-medium text-fg-link hover:underline" onClick={() => setOpen(m.id)}>{when(m.scheduled_at)}</button>
    ) },
    { id: 'employee', header: pt('employee'), cell: (m) => m.employee?.name ?? '—' },
    { id: 'manager', header: pt('manager'), hideBelow: 'md', cell: (m) => m.manager?.name ?? '—' },
    { id: 'actions', header: pt('openActions'), hideBelow: 'md', cell: (m) => m.open_actions },
    { id: 'status', header: pt('statusLabel'), cell: (m) => <StatusBadge status={m.status} /> },
  ];
  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-end gap-3">
        <ScopeSelect value={q.scope} onChange={(scope) => setQ({ ...q, scope, page: 1 })} />
        <Select label={pt('statusLabel')} value={q.status ?? ''} onChange={(e) => setQ({ ...q, status: e.target.value, page: 1 })}
          options={[{ value: '', label: pt('allStatuses') }, ...STATUSES.map((s) => ({ value: s, label: pt(`status.${s}`) }))]} />
        <div className="ms-auto"><Button onClick={() => setCreating(true)}>{pt('scheduleMeeting')}</Button></div>
      </div>
      <ResourceView resource={meetings} empty={pt('noMeetings')} isEmpty={(p) => p.data.length === 0}>
        {(p) => (
          <div className="rounded-lg border border-line bg-surface">
            <DataTable caption={pt('meetings')} hideCaption columns={columns} rows={p.data} getRowId={(m) => m.id} />
            <Pager meta={p.meta} onPage={(page) => setQ({ ...q, page })} />
          </div>
        )}
      </ResourceView>
      {creating && <MeetingDialog onClose={() => setCreating(false)} onSaved={(m) => { setCreating(false); meetings.reload(); setOpen(m.id); }} />}
      {open && <MeetingDrawer id={open} onClose={() => setOpen(null)} onChanged={meetings.reload} />}
    </div>
  );
}

function MeetingDialog({ onClose, onSaved }: { onClose: () => void; onSaved: (m: Meeting) => void }) {
  const { pt, t } = usePerfT();
  const source = usePerformanceSource();
  const can = useCan();
  const manages = can(P.PerformanceReview, P.PerformanceManage);
  const people = useResource(useCallback((s: AbortSignal) => (manages ? source.people('', s) : Promise.resolve([] as Person[])), [source, manages]));
  const self = useResource(useCallback((s: AbortSignal) => source.history(null, s).then((h) => h.employee), [source]));
  const [employee, setEmployee] = useState('');
  const [at, setAt] = useState('');
  const [duration, setDuration] = useState('30');
  const [agenda, setAgenda] = useState('');
  const { error, busy, save } = useSave();
  const fields = validationErrors(error);
  const options = [...(self.data ? [{ value: self.data.id, label: pt('withMyManager') }] : []), ...(people.data ?? []).filter((p) => p.id !== self.data?.id).map((p) => ({ value: p.id, label: p.name }))];
  const target = employee || options[0]?.value || '';
  return (
    <Modal open onClose={onClose} title={pt('scheduleMeeting')}
      footer={<><Button variant="secondary" onClick={onClose}>{t('ui.cancel')}</Button><Button loading={busy} disabled={!at || !target} onClick={() => save(async () => onSaved(await source.saveMeeting({ employee_id: target, scheduled_at: new Date(at).toISOString(), duration_minutes: Number(duration), agenda: agenda || null })))}>{pt('schedule')}</Button></>}>
      <div className="grid gap-4 sm:grid-cols-2">
        <FormError message={formError(error, pt('saveError'))} />
        <div className="sm:col-span-2"><Select label={pt('with')} required value={target} error={fields.employee_id} onChange={(e) => setEmployee(e.target.value)} options={options} /></div>
        <Input label={pt('when')} type="datetime-local" required value={at} error={fields.scheduled_at} onChange={(e) => setAt(e.target.value)} />
        <Input label={pt('durationMinutes')} type="number" min={5} max={480} value={duration} error={fields.duration_minutes} onChange={(e) => setDuration(e.target.value)} />
        <TextArea label={pt('agenda')} value={agenda} onChange={setAgenda} error={fields.agenda} />
      </div>
    </Modal>
  );
}

function MeetingDrawer({ id, onClose, onChanged }: { id: string; onClose: () => void; onChanged: () => void }) {
  const { pt, when } = usePerfT();
  const source = usePerformanceSource();
  const meeting = useResource(useCallback((s: AbortSignal) => source.meeting(id, s), [source, id]));
  const m = meeting.data;
  return (
    <Drawer open onClose={onClose} size="lg" title={m ? when(m.scheduled_at) : pt('meeting')} description={m ? `${m.employee?.name ?? ''} · ${m.manager?.name ?? ''}` : undefined}>
      <ResourceView resource={meeting} empty="">
        {(m) => <MeetingNotes key={m.version} meeting={m} onSaved={() => { meeting.reload(); onChanged(); }} />}
      </ResourceView>
    </Drawer>
  );
}

function MeetingNotes({ meeting: m, onSaved }: { meeting: Meeting; onSaved: () => void }) {
  const { pt } = usePerfT();
  const source = usePerformanceSource();
  const [agenda, setAgenda] = useState(m.agenda ?? '');
  const [shared, setShared] = useState(m.shared_notes ?? '');
  const [secret, setSecret] = useState(m.private_notes ?? '');
  const [items, setItems] = useState<ActionItem[]>(m.action_items ?? []);
  const [draft, setDraft] = useState('');
  const { error, busy, save } = useSave();
  const fields = validationErrors(error);
  const submit = (status?: MeetingStatus) => save(async () => {
    await source.saveMeeting({ version: m.version, agenda: agenda || null, shared_notes: shared || null, action_items: items, ...(m.is_manager ? { private_notes: secret || null } : {}), ...(status ? { status } : {}) }, m.id);
    onSaved();
  });
  if (m.status === 'cancelled') {
    return (
      <div className="space-y-6">
        <StatusBadge status={m.status} />
        <p className="text-sm text-fg-muted">{pt('meetingCancelled')}</p>
        {([['agenda', m.agenda], ['sharedNotes', m.shared_notes], ...(m.is_manager ? [['privateNotes', m.private_notes]] : [])] as [string, string | null | undefined][]).map(([label, text]) => text ? (
          <Section key={label} title={pt(label)}><p className="whitespace-pre-wrap text-sm text-fg">{text}</p></Section>
        ) : null)}
        {(m.action_items ?? []).length > 0 && (
          <Section title={pt('actionItems')}><ul className="list-disc space-y-1 ps-5 text-sm text-fg">{(m.action_items ?? []).map((a, i) => <li key={i}>{a.text} · {pt(`owner.${a.owner}`)}</li>)}</ul></Section>
        )}
      </div>
    );
  }
  return (
    <div className="space-y-6">
      <StatusBadge status={m.status} />
      <FormError message={formError(error, pt('saveError'))} />
      <div className="grid gap-4">
        <TextArea label={pt('agenda')} value={agenda} onChange={setAgenda} error={fields.agenda} />
        <TextArea label={pt('sharedNotes')} value={shared} onChange={setShared} error={fields.shared_notes} />
        {m.is_manager && <TextArea label={pt('privateNotes')} value={secret} onChange={setSecret} error={fields.private_notes} />}
        {m.is_manager && <p className="-mt-2 text-xs text-fg-muted">{pt('privateNotesHint')}</p>}
      </div>
      <Section title={pt('actionItems')}>
        <ul className="space-y-2">{items.map((a, i) => (
          <li key={i} className="flex items-center justify-between gap-2">
            <Checkbox label={`${a.text} · ${pt(`owner.${a.owner}`)}`} checked={a.done} onChange={(e) => setItems(items.map((x, j) => (j === i ? { ...x, done: e.target.checked } : x)))} />
            <Button size="sm" variant="tertiary" onClick={() => setItems(items.filter((_, j) => j !== i))}>{pt('remove')}</Button>
          </li>
        ))}</ul>
        <div className="flex flex-wrap items-end gap-2">
          <Input label={pt('newAction')} maxLength={300} value={draft} onChange={(e) => setDraft(e.target.value)} />
          <Button size="sm" variant="secondary" disabled={!draft.trim()} onClick={() => { setItems([...items, { text: draft.trim(), owner: m.is_manager ? 'manager' : 'employee', done: false }]); setDraft(''); }}>{pt('add')}</Button>
        </div>
      </Section>
      <div className="flex flex-wrap gap-2">
        <Button loading={busy} onClick={() => submit()}>{pt('save')}</Button>
        {m.status === 'scheduled' && <Button variant="secondary" loading={busy} onClick={() => submit('completed')}>{pt('markHeld')}</Button>}
        {m.status === 'scheduled' && <Button variant="tertiary" loading={busy} onClick={() => submit('cancelled')}>{pt('cancelMeeting')}</Button>}
      </div>
    </div>
  );
}
