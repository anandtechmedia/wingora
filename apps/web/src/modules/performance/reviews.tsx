import { useCallback, useState } from 'react';
import { Permission as P } from '@wingora/permissions';
import { usePerformanceSource, type Answers, type Cycle, type CycleInput, type FeedbackRequest, type ListQuery, type Question, type Relationship, type Review, type ReviewStatus } from '@/api/performance';
import { Button, Checkbox, ConfirmationDialog, DataTable, Drawer, EmptyState, Input, Modal, Select, type DataTableColumn } from '@/components/ui';
import { FormError, formError, ResourceView, useResource, validationErrors } from '@/modules/employees/shared';
import { Pager } from '@/modules/attendance';
import { TextArea } from '@/modules/time/shared';
import { useAction, useCan, useSave } from '@/modules/recruitment/shared';
import { ScopeSelect } from './objectives';
import { Section, StatusBadge, usePerfT } from './shared';

const REVIEW_STATUSES: ReviewStatus[] = ['pending_self', 'pending_manager', 'completed', 'acknowledged'];
const RELATIONSHIPS: Relationship[] = ['peer', 'direct_report', 'manager', 'other'];

export function Reviews() {
  const { pt } = usePerfT();
  const source = usePerformanceSource();
  const [q, setQ] = useState<ListQuery>({ page: 1, scope: 'mine' });
  const [open, setOpen] = useState<string | null>(null);
  const reviews = useResource(useCallback((s: AbortSignal) => source.reviews(q, s), [source, q]));
  const columns: DataTableColumn<Review>[] = [
    { id: 'employee', header: pt('employee'), primary: true, cell: (r) => (
      <button type="button" className="text-start font-medium text-fg-link hover:underline" onClick={() => setOpen(r.id)}>{r.employee?.name ?? '—'}</button>
    ) },
    { id: 'cycle', header: pt('cycle'), cell: (r) => r.cycle.name },
    { id: 'manager', header: pt('manager'), hideBelow: 'md', cell: (r) => r.manager?.name ?? '—' },
    { id: 'rating', header: pt('rating'), hideBelow: 'md', cell: (r) => (r.rating ? `${r.rating} / ${r.cycle.rating_scale}` : '—') },
    { id: 'status', header: pt('statusLabel'), cell: (r) => <StatusBadge status={r.status} /> },
  ];
  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-end gap-3">
        <ScopeSelect value={q.scope} onChange={(scope) => setQ({ ...q, scope, page: 1 })} />
        <Select label={pt('statusLabel')} value={q.status ?? ''} onChange={(e) => setQ({ ...q, status: e.target.value, page: 1 })}
          options={[{ value: '', label: pt('allStatuses') }, ...REVIEW_STATUSES.map((s) => ({ value: s, label: pt(`status.${s}`) }))]} />
      </div>
      <ResourceView resource={reviews} empty={pt('noReviews')} isEmpty={(p) => p.data.length === 0}>
        {(p) => (
          <div className="rounded-lg border border-line bg-surface">
            <DataTable caption={pt('reviews')} hideCaption columns={columns} rows={p.data} getRowId={(r) => r.id} />
            <Pager meta={p.meta} onPage={(page) => setQ({ ...q, page })} />
          </div>
        )}
      </ResourceView>
      {open && <ReviewDrawer id={open} onClose={() => setOpen(null)} onChanged={reviews.reload} />}
    </div>
  );
}

function AnswerFields({ questions, scale, answers, onChange, errors }: { questions: Question[]; scale: number; answers: Answers; onChange: (a: Answers) => void; errors: Record<string, string> }) {
  const { pt } = usePerfT();
  return (
    <div className="grid gap-3 sm:grid-cols-2">
      {questions.map((q) => q.type === 'rating'
        ? <Select key={q.key} label={q.label} required value={String(answers[q.key] ?? '')} placeholder={pt('chooseRating')} error={errors[`answers.${q.key}`]}
            onChange={(e) => onChange({ ...answers, [q.key]: Number(e.target.value) })} options={Array.from({ length: scale }, (_, i) => ({ value: String(i + 1), label: String(i + 1) }))} />
        : <TextArea key={q.key} label={q.label} required value={String(answers[q.key] ?? '')} error={errors[`answers.${q.key}`]} onChange={(v) => onChange({ ...answers, [q.key]: v })} />)}
    </div>
  );
}

function AnswerList({ questions, answers }: { questions: Question[]; answers: Answers }) {
  return (
    <dl className="space-y-2 text-sm">{questions.map((q) => (
      <div key={q.key}><dt className="font-medium text-fg">{q.label}</dt><dd className="whitespace-pre-line text-fg-muted">{String(answers[q.key] ?? '—')}</dd></div>
    ))}</dl>
  );
}

function ReviewDrawer({ id, onClose, onChanged }: { id: string; onClose: () => void; onChanged: () => void }) {
  const { pt } = usePerfT();
  const source = usePerformanceSource();
  const review = useResource(useCallback((s: AbortSignal) => source.review(id, s), [source, id]));
  const [answers, setAnswers] = useState<Answers>({});
  const [rating, setRating] = useState('');
  const [asking, setAsking] = useState(false);
  const { error, busy, save } = useSave();
  const { busy: acting, run } = useAction();
  const errors = validationErrors(error);
  const done = () => { setAnswers({}); review.reload(); onChanged(); };
  return (
    <Drawer open onClose={onClose} size="lg" title={review.data ? `${review.data.employee?.name ?? ''} · ${review.data.cycle.name}` : pt('review')}>
      <ResourceView resource={review} empty="">
        {(r) => {
          const questions = r.questions ?? [];
          const scale = r.cycle.rating_scale;
          return (
            <div className="space-y-6">
              <div className="flex flex-wrap items-center gap-3"><StatusBadge status={r.status} />
                {r.rating !== null && <span className="text-sm text-fg">{pt('ratingOf', { rating: r.rating, scale })}</span>}</div>
              <FormError message={formError(error, pt('saveError'))} />
              <Section title={pt('selfAssessment')}>
                {r.self_answers ? <AnswerList questions={questions} answers={r.self_answers} /> : r.can_self ? (
                  <div className="space-y-3">
                    <AnswerFields questions={questions} scale={scale} answers={answers} onChange={setAnswers} errors={errors} />
                    <Button loading={busy} onClick={() => save(async () => { await source.submitSelf(r.id, r.version, answers); done(); })}>{pt('submitSelf')}</Button>
                  </div>
                ) : <p className="text-sm text-fg-muted">{pt('awaitingSelf')}</p>}
              </Section>
              <Section title={pt('managerAssessment')}>
                {r.manager_answers ? <AnswerList questions={questions} answers={r.manager_answers} /> : r.can_manage && r.status === 'pending_manager' ? (
                  <div className="space-y-3">
                    <AnswerFields questions={questions} scale={scale} answers={answers} onChange={setAnswers} errors={errors} />
                    <Select label={pt('overallRating')} required value={rating} placeholder={pt('chooseRating')} error={errors.rating} onChange={(e) => setRating(e.target.value)}
                      options={Array.from({ length: scale }, (_, i) => ({ value: String(i + 1), label: String(i + 1) }))} />
                    <Button loading={busy} disabled={!rating} onClick={() => save(async () => { await source.submitManager(r.id, r.version, answers, Number(rating)); done(); })}>{pt('submitManager')}</Button>
                  </div>
                ) : <p className="text-sm text-fg-muted">{pt(r.status === 'pending_self' || r.status === 'pending_manager' ? 'awaitingManager' : 'hidden')}</p>}
              </Section>
              <Section title={pt('feedback360')}>
                {(r.feedback ?? []).length === 0 ? <p className="text-sm text-fg-muted">{pt('noFeedback')}</p> : (
                  <ul className="space-y-2">{r.feedback!.map((f) => (
                    <li key={f.id} className="space-y-1 rounded-md border border-line p-3 text-sm">
                      <div className="flex flex-wrap justify-between gap-2"><span className="font-medium text-fg">{f.reviewer ?? pt('anonymous')} · {pt(`relationship.${f.relationship}`)}</span><StatusBadge status={f.status} /></div>
                      {f.answers && <><p><span className="font-medium">{pt('strengths')}:</span> {f.answers.strengths}</p><p><span className="font-medium">{pt('improvements')}:</span> {f.answers.improvements}</p></>}
                    </li>
                  ))}</ul>
                )}
                {r.can_manage && r.cycle.status === 'open' && r.status !== 'acknowledged' && <Button variant="secondary" size="sm" onClick={() => setAsking(true)}>{pt('requestFeedback')}</Button>}
              </Section>
              {r.status === 'completed' && !r.can_manage && (
                <Button loading={acting === 'ack'} onClick={async () => { if (await run('ack', () => source.acknowledge(r.id, r.version), pt('acknowledged'))) done(); }}>{pt('acknowledge')}</Button>
              )}
              {asking && <FeedbackRequestDialog review={r} onClose={() => setAsking(false)} onSaved={() => { setAsking(false); done(); }} />}
            </div>
          );
        }}
      </ResourceView>
    </Drawer>
  );
}

function FeedbackRequestDialog({ review, onClose, onSaved }: { review: Review; onClose: () => void; onSaved: () => void }) {
  const { pt, t } = usePerfT();
  const source = usePerformanceSource();
  const [search, setSearch] = useState('');
  const people = useResource(useCallback((s: AbortSignal) => source.people(search.trim(), s), [source, search]));
  const [reviewer, setReviewer] = useState('');
  const [relationship, setRelationship] = useState<Relationship>('peer');
  const [anonymous, setAnonymous] = useState(true);
  const { error, busy, save } = useSave();
  const fields = validationErrors(error);
  const options = (people.data ?? []).filter((p) => p.id !== review.employee?.id);
  return (
    <Modal open onClose={onClose} title={pt('requestFeedback')} description={review.employee?.name}
      footer={<><Button variant="secondary" onClick={onClose}>{t('ui.cancel')}</Button><Button loading={busy} disabled={!reviewer} onClick={() => save(async () => { await source.requestFeedback(review.id, { reviewer_id: reviewer, relationship, anonymous }); onSaved(); })}>{pt('send')}</Button></>}>
      <div className="grid gap-4">
        <FormError message={formError(error, pt('saveError'))} />
        <Input label={pt('searchPeople')} type="search" value={search} onChange={(e) => setSearch(e.target.value)} />
        <Select label={pt('reviewer')} required value={reviewer} placeholder={pt('choosePerson')} error={fields.reviewer_id} onChange={(e) => setReviewer(e.target.value)}
          options={options.map((p) => ({ value: p.id, label: `${p.name} · ${p.employee_number}` }))} />
        <Select label={pt('relationshipLabel')} value={relationship} onChange={(e) => setRelationship(e.target.value as Relationship)} options={RELATIONSHIPS.map((x) => ({ value: x, label: pt(`relationship.${x}`) }))} />
        <Checkbox label={pt('anonymousLabel')} description={pt('anonymousHint')} checked={anonymous} onChange={(e) => setAnonymous(e.target.checked)} />
      </div>
    </Modal>
  );
}

export function FeedbackInbox() {
  const { pt } = usePerfT();
  const source = usePerformanceSource();
  const [page, setPage] = useState(1);
  const [answering, setAnswering] = useState<FeedbackRequest | null>(null);
  const requests = useResource(useCallback((s: AbortSignal) => source.feedback(page, s), [source, page]));
  const { busy, run } = useAction();
  return (
    <ResourceView resource={requests} empty={pt('noFeedbackRequests')} isEmpty={(p) => p.data.length === 0}>
      {(p) => (
        <div className="space-y-3">
          <ul className="space-y-2">{p.data.map((f) => (
            <li key={f.id} className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-line bg-surface p-4">
              <div><p className="font-medium text-fg">{f.subject?.name}</p><p className="text-sm text-fg-muted">{f.cycle.name} · {pt(`relationship.${f.relationship}`)}{f.anonymous ? ` · ${pt('anonymous')}` : ''}</p></div>
              <div className="flex items-center gap-2"><StatusBadge status={f.status} />
                {f.status === 'requested' && f.cycle.status === 'open' && <>
                  <Button size="sm" onClick={() => setAnswering(f)}>{pt('giveFeedback')}</Button>
                  <Button size="sm" variant="tertiary" loading={busy === f.id} onClick={async () => { if (await run(f.id, () => source.declineFeedback(f.id), pt('declined'))) requests.reload(); }}>{pt('decline')}</Button>
                </>}</div>
            </li>
          ))}</ul>
          <Pager meta={p.meta} onPage={setPage} />
          {answering && <FeedbackDialog request={answering} onClose={() => setAnswering(null)} onSaved={() => { setAnswering(null); requests.reload(); }} />}
        </div>
      )}
    </ResourceView>
  );
}

function FeedbackDialog({ request, onClose, onSaved }: { request: FeedbackRequest; onClose: () => void; onSaved: () => void }) {
  const { pt, t } = usePerfT();
  const source = usePerformanceSource();
  const [strengths, setStrengths] = useState('');
  const [improvements, setImprovements] = useState('');
  const [rating, setRating] = useState('');
  const { error, busy, save } = useSave();
  const fields = validationErrors(error);
  return (
    <Modal open onClose={onClose} size="lg" title={pt('feedbackFor', { name: request.subject?.name ?? '' })} description={request.anonymous ? pt('anonymousHint') : pt('namedHint')}
      footer={<><Button variant="secondary" onClick={onClose}>{t('ui.cancel')}</Button><Button loading={busy} disabled={!strengths.trim() || !improvements.trim()} onClick={() => save(async () => { await source.submitFeedback(request.id, { strengths, improvements, rating: rating ? Number(rating) : null }); onSaved(); })}>{pt('send')}</Button></>}>
      <div className="grid gap-4 sm:grid-cols-2">
        <FormError message={formError(error, pt('saveError'))} />
        <TextArea label={pt('strengths')} required value={strengths} onChange={setStrengths} error={fields.strengths} />
        <TextArea label={pt('improvements')} required value={improvements} onChange={setImprovements} error={fields.improvements} />
        <Select label={pt('ratingOptional')} value={rating} onChange={(e) => setRating(e.target.value)}
          options={[{ value: '', label: pt('noRating') }, ...Array.from({ length: request.cycle.rating_scale }, (_, i) => ({ value: String(i + 1), label: String(i + 1) }))]} />
      </div>
    </Modal>
  );
}

export function Cycles() {
  const { pt, date } = usePerfT();
  const source = usePerformanceSource();
  const can = useCan();
  const [page, setPage] = useState(1);
  const [editing, setEditing] = useState<Cycle | 'new' | null>(null);
  const [confirm, setConfirm] = useState<{ cycle: Cycle; action: 'launch' | 'close' } | null>(null);
  const cycles = useResource(useCallback((s: AbortSignal) => source.cycles(page, s), [source, page]));
  const { run } = useAction();
  const configure = can(P.PerformanceConfigure);
  const columns: DataTableColumn<Cycle>[] = [
    { id: 'name', header: pt('cycle'), primary: true, cell: (c) => configure && c.status === 'draft'
      ? <button type="button" className="text-start font-medium text-fg-link hover:underline" onClick={() => setEditing(c)}>{c.name}</button> : <span className="font-medium">{c.name}</span> },
    { id: 'period', header: pt('period'), hideBelow: 'md', cell: (c) => `${date(c.starts_on)} – ${date(c.ends_on)}` },
    { id: 'done', header: pt('completedCount'), hideBelow: 'md', cell: (c) => (c.review_count !== null ? `${c.completed_count ?? 0} / ${c.review_count}` : '—') },
    { id: 'status', header: pt('statusLabel'), cell: (c) => <StatusBadge status={c.status} /> },
    { id: 'actions', header: pt('actions'), cell: (c) => configure && c.status !== 'closed'
      ? <Button size="sm" variant="secondary" onClick={() => setConfirm({ cycle: c, action: c.status === 'draft' ? 'launch' : 'close' })}>{pt(c.status === 'draft' ? 'launch' : 'close')}</Button> : null },
  ];
  return (
    <div className="space-y-4">
      {configure && <div className="flex justify-end"><Button onClick={() => setEditing('new')}>{pt('newCycle')}</Button></div>}
      <ResourceView resource={cycles} empty={pt('noCycles')} isEmpty={(p) => p.data.length === 0}>
        {(p) => (
          <div className="rounded-lg border border-line bg-surface">
            <DataTable caption={pt('cycles')} hideCaption columns={columns} rows={p.data} getRowId={(c) => c.id} />
            <Pager meta={p.meta} onPage={setPage} />
          </div>
        )}
      </ResourceView>
      {editing && <CycleDialog cycle={editing === 'new' ? null : editing} onClose={() => setEditing(null)} onSaved={() => { setEditing(null); cycles.reload(); }} />}
      {confirm && (
        <ConfirmationDialog open onCancel={() => setConfirm(null)} tone={confirm.action === 'close' ? 'danger' : 'primary'}
          title={pt(`${confirm.action}Title`, { name: confirm.cycle.name })} description={pt(`${confirm.action}Confirm`)} confirmLabel={pt(confirm.action)}
          onConfirm={async () => {
            const c = confirm.cycle;
            const ok = await run(c.id, () => (confirm.action === 'launch' ? source.launchCycle(c.id, c.version) : source.closeCycle(c.id, c.version)), pt(confirm.action === 'launch' ? 'launched' : 'closed'));
            setConfirm(null);
            if (ok) cycles.reload();
          }} />
      )}
    </div>
  );
}

const DEFAULT_QUESTIONS: { key: string; type: Question['type'] }[] = [{ key: 'achievements', type: 'text' }, { key: 'growth', type: 'text' }, { key: 'values', type: 'rating' }];

function CycleDialog({ cycle, onClose, onSaved }: { cycle: Cycle | null; onClose: () => void; onSaved: () => void }) {
  const { pt, t } = usePerfT();
  const source = usePerformanceSource();
  const [form, setForm] = useState<CycleInput>(cycle ? { ...cycle } : { name: '', starts_on: '', ends_on: '', self_due_on: null, manager_due_on: null, rating_scale: 5, questions: DEFAULT_QUESTIONS.map((q) => ({ ...q, label: pt(`defaultQuestion.${q.key}`) })) });
  const { error, busy, save } = useSave();
  const fields = validationErrors(error);
  const set = (patch: Partial<CycleInput>) => setForm((f) => ({ ...f, ...patch }));
  const setQ = (i: number, patch: Partial<Question>) => set({ questions: form.questions.map((q, j) => (j === i ? { ...q, ...patch } : q)) });
  return (
    <Modal open onClose={onClose} size="lg" title={cycle ? pt('editCycle') : pt('newCycle')}
      footer={<><Button variant="secondary" onClick={onClose}>{t('ui.cancel')}</Button><Button loading={busy} onClick={() => save(async () => { await source.saveCycle({ ...form, ...(cycle ? { version: cycle.version } : {}) }, cycle?.id); onSaved(); })}>{pt('save')}</Button></>}>
      <div className="grid gap-4 sm:grid-cols-2">
        <FormError message={formError(error, pt('saveError'))} />
        <div className="sm:col-span-2"><Input label={pt('name')} required maxLength={120} value={form.name} error={fields.name} onChange={(e) => set({ name: e.target.value })} /></div>
        <Input label={pt('startsOn')} type="date" required value={form.starts_on} error={fields.starts_on} onChange={(e) => set({ starts_on: e.target.value })} />
        <Input label={pt('endsOn')} type="date" required value={form.ends_on} error={fields.ends_on} onChange={(e) => set({ ends_on: e.target.value })} />
        <Input label={pt('selfDue')} type="date" value={form.self_due_on ?? ''} onChange={(e) => set({ self_due_on: e.target.value || null })} />
        <Input label={pt('managerDue')} type="date" value={form.manager_due_on ?? ''} onChange={(e) => set({ manager_due_on: e.target.value || null })} />
        <Select label={pt('ratingScale')} value={String(form.rating_scale)} onChange={(e) => set({ rating_scale: Number(e.target.value) })} options={[3, 4, 5, 10].map((n) => ({ value: String(n), label: pt('scaleOf', { n }) }))} />
        <fieldset className="space-y-3 sm:col-span-2">
          <legend className="text-sm font-medium text-fg">{pt('questions')}</legend>
          {fields.questions && <p role="alert" className="text-sm text-danger-fg">{fields.questions}</p>}
          {form.questions.map((q, i) => (
            <div key={i} className="grid gap-2 sm:grid-cols-[2fr_1fr_auto] sm:items-end">
              <Input label={pt('questionN', { n: i + 1 })} required maxLength={300} value={q.label} error={fields[`questions.${i}.label`]} onChange={(e) => setQ(i, { label: e.target.value })} />
              <Select label={pt('answerType')} value={q.type} onChange={(e) => setQ(i, { type: e.target.value as Question['type'] })} options={(['text', 'rating'] as const).map((x) => ({ value: x, label: pt(`answer.${x}`) }))} />
              <Button variant="tertiary" disabled={form.questions.length === 1} onClick={() => set({ questions: form.questions.filter((_, j) => j !== i) })}>{pt('remove')}</Button>
            </div>
          ))}
          {form.questions.length < 20 && <Button variant="secondary" size="sm" onClick={() => set({ questions: [...form.questions, { key: `q${Date.now().toString(36)}`, label: '', type: 'text' }] })}>{pt('addQuestion')}</Button>}
        </fieldset>
      </div>
    </Modal>
  );
}

export function NoAccess() {
  const { pt } = usePerfT();
  return <EmptyState title={pt('nothingToShow')} />;
}
