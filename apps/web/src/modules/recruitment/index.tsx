import { useCallback, useState } from 'react';
import { Permission as P } from '@wingora/permissions';
import { ApiNotConnectedError } from '@/api/dashboard';
import { ApiError } from '@/api/http';
import {
  useRecruitmentSource, type Application, type BoardColumn, type DocumentKind, type FeedbackInput, type Interview, type InterviewMode, type Offer, type OfferAction,
  type Opening, type OpeningInput, type Recommendation, type RecruitmentSettings, type Workplace,
} from '@/api/recruitment';
import { Badge, Button, Card, Checkbox, DataTable, Drawer, EmptyState, Input, Modal, Select, Tabs, useToast, type DataTableColumn } from '@/components/ui';
import { PageHeader } from '@/shell/page-header';
import { DefinitionList, errorMessage, FormError, formError, ResourceView, useResource, validationErrors } from '@/modules/employees/shared';
import { Pager } from '@/modules/attendance';
import { TextArea } from '@/modules/time/shared';
import { FileField } from '@/modules/payroll/shared';
import { AiBadge, ApplicationStatusBadge, localTimeZone, OfferStatusBadge, OpeningStatusBadge, RecruitmentNotConnected, useAction, useCan, useRecruitmentT, useSave } from './shared';

const KINDS: DocumentKind[] = ['resume', 'cover_letter', 'portfolio', 'certificate', 'offer_letter', 'other'];
const RECS: Recommendation[] = ['strong_yes', 'yes', 'no', 'strong_no'];
const OFFER_ACTIONS: Record<string, OfferAction> = { approved: 'approve', sent: 'send', accepted: 'accept', declined: 'decline', withdrawn: 'withdraw' };

export default function Recruitment() {
  const { rt, t } = useRecruitmentT();
  const can = useCan();
  const tabs = [
    can(P.RecruitmentView) && { id: 'pipeline', label: rt('pipeline'), content: <Pipeline /> },
    can(P.RecruitmentView) && { id: 'openings', label: rt('openings'), content: <Openings /> },
    can(P.RecruitmentInterview, P.RecruitmentView) && { id: 'interviews', label: rt('myInterviews'), content: <MyInterviews /> },
    can(P.RecruitmentConfigure) && { id: 'settings', label: rt('settings'), content: <Settings /> },
  ].filter((x): x is { id: string; label: string; content: React.ReactElement } => Boolean(x));
  return (
    <>
      <PageHeader title={t('nav.module.recruitment')} description={rt('subtitle')} />
      {tabs.length > 0 ? <Tabs label={t('nav.module.recruitment')} tabs={tabs} /> : <EmptyState title={rt('nothingToShow')} />}
    </>
  );
}

/** Kanban: a horizontally scrolling row of stage columns. Cards move with the "Move to" menu, which works with keyboard, mouse and touch. */
function Pipeline() {
  const { rt } = useRecruitmentT();
  const source = useRecruitmentSource();
  const { toast } = useToast();
  const can = useCan();
  const [opening, setOpening] = useState('');
  const [q, setQ] = useState('');
  const [selected, setSelected] = useState<string | null>(null);
  const [adding, setAdding] = useState(false);
  const openings = useResource(useCallback((signal: AbortSignal) => source.openings({ status: 'published', page: 1 }, signal), [source]));
  const board = useResource(useCallback((signal: AbortSignal) => source.board({ opening_id: opening || undefined, q: q || undefined }, signal), [source, opening, q]));
  const movable = (board.data ?? []).filter((s) => s.kind === 'active');
  const move = async (card: Application, stageId: string) => {
    if (!stageId || stageId === card.stage?.id) return;
    try {
      await source.move(card.id, stageId, card.version);
      toast({ title: rt('moved'), tone: 'success' });
    } catch (e) {
      toast({ title: e instanceof ApiError && e.status === 409 ? rt('moveConflict') : errorMessage(e, rt('actionFailed')), tone: 'danger' });
    }
    board.reload();
  };
  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-end gap-2">
        <Select label={rt('opening')} value={opening} onChange={(e) => setOpening(e.target.value)} placeholder={rt('allOpenings')}
          options={(openings.data?.data ?? []).map((o) => ({ value: o.id, label: o.title }))} />
        <Input label={rt('searchCandidates')} value={q} onChange={(e) => setQ(e.target.value)} />
        {can(P.RecruitmentManage) && <Button onClick={() => setAdding(true)}>{rt('addCandidate')}</Button>}
      </div>
      {board.error instanceof ApiNotConnectedError ? <RecruitmentNotConnected /> : (
        <ResourceView errorLabel={rt('loadError')} resource={board} empty={rt('noStages')} isEmpty={(d) => d.length === 0}>
          {(columns) => (
            <div className="-mx-4 flex snap-x gap-3 overflow-x-auto px-4 pb-2" role="list" aria-label={rt('pipeline')}>
              {columns.map((col) => <StageColumn key={col.id} col={col} movable={movable} onOpen={setSelected} onMove={move} />)}
            </div>
          )}
        </ResourceView>
      )}
      {selected && <ApplicationDrawer id={selected} onClose={() => { setSelected(null); board.reload(); }} />}
      {adding && <AddCandidateDialog openings={openings.data?.data ?? []} onClose={() => setAdding(false)} onAdded={() => { setAdding(false); board.reload(); }} />}
    </div>
  );
}

function StageColumn({ col, movable, onOpen, onMove }: {
  col: BoardColumn; movable: BoardColumn[]; onOpen: (id: string) => void; onMove: (c: Application, stageId: string) => void;
}) {
  const { rt } = useRecruitmentT();
  return (
    <section role="listitem" aria-label={`${col.name} (${col.cards.length})`} className="w-72 shrink-0 snap-start rounded-lg border border-line bg-canvas p-2">
      <h2 className="flex items-center justify-between px-1 pb-2 text-sm font-semibold text-fg">{col.name}<Badge size="sm">{col.cards.length}</Badge></h2>
      <ul className="space-y-2">
        {col.cards.map((c) => (
          <li key={c.id} className="rounded-md border border-line bg-surface p-3 shadow-xs">
            <button type="button" className="text-left font-medium text-fg hover:underline focus-visible:outline-2 focus-visible:outline-focus" onClick={() => onOpen(c.id)}>{c.candidate?.name}</button>
            <div className="mt-1 text-xs text-fg-muted">{c.opening?.title}</div>
            <div className="mt-2 flex flex-wrap gap-1">
              {c.status !== 'active' && <ApplicationStatusBadge status={c.status} />}
              {c.latest_offer_status && <OfferStatusBadge status={c.latest_offer_status} />}
              {c.candidate?.on_hold && <Badge size="sm" tone="warning" variant="outline">{rt('onHold')}</Badge>}
            </div>
            {c.can.act && c.status === 'active' && (
              <div className="mt-2"><Select label={rt('moveTo', { name: c.candidate?.name ?? '' })} hideLabel value="" placeholder={rt('moveToShort')} onChange={(e) => onMove(c, e.target.value)}
                options={movable.filter((s) => s.id !== c.stage?.id).map((s) => ({ value: s.id, label: s.name }))} /></div>
            )}
          </li>
        ))}
      </ul>
    </section>
  );
}

function AddCandidateDialog({ openings, onClose, onAdded }: { openings: Opening[]; onClose: () => void; onAdded: () => void }) {
  const { rt, t } = useRecruitmentT();
  const source = useRecruitmentSource();
  const [input, setInput] = useState({ opening_id: '', first_name: '', last_name: '', email: '', phone: '', source: 'referral', consent_confirmed: false });
  const [resume, setResume] = useState<File | null>(null);
  const { error, busy, save } = useSave();
  const fields = validationErrors(error);
  return (
    <Modal open onClose={onClose} title={rt('addCandidate')} description={rt('addCandidateHint')}
      footer={<><Button variant="secondary" onClick={onClose}>{t('ui.cancel')}</Button>
        <Button loading={busy} disabled={!input.opening_id || !input.consent_confirmed} onClick={() => save(async () => { await source.addCandidate({ ...input, phone: input.phone || null, resume }); onAdded(); })}>{rt('add')}</Button></>}>
      <div className="grid gap-4 sm:grid-cols-2">
        <Select label={rt('opening')} required value={input.opening_id} onChange={(e) => setInput({ ...input, opening_id: e.target.value })} placeholder={rt('chooseOpening')} error={fields.opening_id}
          options={openings.map((o) => ({ value: o.id, label: o.title }))} />
        <Select label={rt('sourceLabel')} value={input.source} onChange={(e) => setInput({ ...input, source: e.target.value })}
          options={['referral', 'agency', 'sourced', 'other'].map((s) => ({ value: s, label: rt(`source.${s}`) }))} />
        <Input label={rt('firstName')} required value={input.first_name} onChange={(e) => setInput({ ...input, first_name: e.target.value })} error={fields.first_name} />
        <Input label={rt('lastName')} required value={input.last_name} onChange={(e) => setInput({ ...input, last_name: e.target.value })} error={fields.last_name} />
        <Input label={rt('email')} type="email" required value={input.email} onChange={(e) => setInput({ ...input, email: e.target.value })} error={fields.email} />
        <Input label={rt('phone')} type="tel" value={input.phone} onChange={(e) => setInput({ ...input, phone: e.target.value })} error={fields.phone} />
        <FileField label={rt('resume')} accept=".pdf,.docx" onFile={setResume} />
        <div className="sm:col-span-2"><Checkbox label={rt('consentConfirmed')} checked={input.consent_confirmed} onChange={(e) => setInput({ ...input, consent_confirmed: e.target.checked })} /></div>
        <FormError message={Object.keys(fields).length ? null : error ? formError(error, rt('actionFailed')) : null} />
      </div>
    </Modal>
  );
}

function ApplicationDrawer({ id, onClose }: { id: string; onClose: () => void }) {
  const { rt } = useRecruitmentT();
  const source = useRecruitmentSource();
  const app = useResource(useCallback((signal: AbortSignal) => source.application(id, signal), [source, id]));
  const a = app.data;
  return (
    <Drawer open onClose={onClose} size="lg" title={a?.candidate?.name ?? rt('candidate')} description={a ? `${a.opening?.title ?? ''} · ${a.stage?.name ?? ''}` : undefined}>
      <ResourceView errorLabel={rt('loadError')} resource={app} empty={rt('notFound')}>
        {(d) => {
          const tabs = [
            { id: 'profile', label: rt('profile'), content: <Profile app={d} onChanged={app.reload} onErased={onClose} /> },
            { id: 'interviews', label: rt('interviews'), content: <ApplicationInterviews app={d} /> },
            d.can.offers && { id: 'offers', label: rt('offers'), content: <Offers app={d} onChanged={app.reload} /> },
            d.can.manage && { id: 'ai', label: rt('aiAssist'), content: <AiAssist app={d} onChanged={app.reload} /> },
          ].filter((x): x is { id: string; label: string; content: React.ReactElement } => Boolean(x));
          return <Tabs label={rt('candidate')} tabs={tabs} />;
        }}
      </ResourceView>
    </Drawer>
  );
}

function Profile({ app, onChanged, onErased }: { app: Application; onChanged: () => void; onErased: () => void }) {
  const { rt, t, when } = useRecruitmentT();
  const source = useRecruitmentSource();
  const { busy, run } = useAction();
  const [dialog, setDialog] = useState<'reject' | 'erase' | null>(null);
  const [reason, setReason] = useState('');
  const [file, setFile] = useState<File | null>(null);
  const [kind, setKind] = useState<DocumentKind>('other');
  const c = app.candidate;
  const done = (ok: boolean) => { if (ok) { setDialog(null); setReason(''); onChanged(); } };
  return (
    <div className="space-y-6 pt-4">
      <div className="flex flex-wrap gap-2"><ApplicationStatusBadge status={app.status} />{c?.on_hold && <Badge size="sm" tone="warning" variant="outline">{rt('onHold')}</Badge>}</div>
      <DefinitionList items={[
        [rt('email'), c?.email ?? '—'], [rt('phone'), c?.phone ?? '—'], [rt('location'), c?.location ?? '—'], [rt('sourceLabel'), c ? rt(`source.${c.source}`) : '—'],
        [rt('appliedAt'), app.applied_at ? when(app.applied_at) : '—'], [rt('consent'), app.consent.version ? rt('consentGiven', { version: app.consent.version, date: app.consent.given_at ? when(app.consent.given_at) : '' }) : '—'],
        [rt('retainUntil'), app.retain_until ? when(app.retain_until) : rt('whileActive')], ...(app.rejection_reason ? [[rt('rejectionReason'), app.rejection_reason] as [string, string]] : []),
      ]} />
      {app.cover_letter && <section><h3 className="text-sm font-semibold text-fg">{rt('coverLetter')}</h3><p className="mt-1 whitespace-pre-line text-sm text-fg">{app.cover_letter}</p></section>}
      <section>
        <h3 className="text-sm font-semibold text-fg">{rt('documents')}</h3>
        <ul className="mt-2 divide-y divide-line">
          {(app.documents ?? []).map((d) => (
            <li key={d.id} className="flex flex-col gap-2 py-2 sm:flex-row sm:items-center sm:justify-between">
              <div className="min-w-0 text-sm"><div className="truncate text-fg">{d.name}</div>
                {d.suggested_kind && <div className="text-xs text-fg-muted"><AiBadge /> {rt('suggestedKind', { kind: rt(`kind.${d.suggested_kind}`) })}</div>}</div>
              <div className="flex flex-wrap items-center gap-2">
                {app.can.manage ? <Select label={rt('documentKind', { name: d.name ?? '' })} hideLabel value={d.kind} onChange={(e) => run(d.id, () => source.setDocumentKind(d.id, e.target.value as DocumentKind), rt('saved')).then((ok) => ok && onChanged())}
                  options={KINDS.map((k) => ({ value: k, label: rt(`kind.${k}`) }))} /> : <Badge size="sm">{rt(`kind.${d.kind}`)}</Badge>}
                <Button size="sm" variant="tertiary" loading={busy === `dl${d.id}`} onClick={() => run(`dl${d.id}`, () => source.downloadDocument(d.id))}>{rt('download')}</Button>
              </div>
            </li>
          ))}
        </ul>
        {app.can.manage && !c?.anonymized && <div className="mt-2 grid gap-2 sm:grid-cols-[1fr_auto_auto] sm:items-end">
          <FileField label={rt('uploadDocument')} accept=".pdf,.docx,.png,.jpg,.jpeg" onFile={setFile} />
          <Select label={rt('kindLabel')} value={kind} onChange={(e) => setKind(e.target.value as DocumentKind)} options={KINDS.map((k) => ({ value: k, label: rt(`kind.${k}`) }))} />
          <Button size="sm" disabled={!file} loading={busy === 'upload'} onClick={() => file && run('upload', () => source.uploadDocument(app.id, file, kind), rt('uploaded')).then((ok) => ok && onChanged())}>{rt('upload')}</Button>
        </div>}
      </section>
      {app.can.manage && <div className="flex flex-wrap gap-2 border-t border-line pt-4">
        {app.status === 'active' && <Button variant="danger" size="sm" onClick={() => setDialog('reject')}>{rt('reject')}</Button>}
        {c && !c.anonymized && <Button variant="secondary" size="sm" loading={busy === 'hold'} onClick={() => run('hold', () => source.hold(app.id, !c.on_hold), rt('saved')).then(done)}>{c.on_hold ? rt('releaseHold') : rt('hold')}</Button>}
        {c && !c.anonymized && app.status !== 'hired' && <Button variant="tertiary" size="sm" onClick={() => setDialog('erase')}>{rt('erase')}</Button>}
      </div>}
      {dialog && <Modal open size="sm" onClose={() => setDialog(null)} title={rt(dialog === 'reject' ? 'rejectTitle' : 'eraseTitle')} description={rt(dialog === 'reject' ? 'rejectHint' : 'eraseHint')}
        footer={<><Button variant="secondary" onClick={() => setDialog(null)}>{t('ui.cancel')}</Button>
          <Button variant="danger" disabled={reason.trim().length < 3} loading={busy === dialog}
            onClick={() => (dialog === 'reject' ? run('reject', () => source.reject(app.id, reason.trim(), app.version), rt('rejected')).then(done)
              : run('erase', () => source.erase(app.id, reason.trim()), rt('erased')).then((ok) => ok && onErased()))}>{rt(dialog)}</Button></>}>
        <TextArea label={rt('reason')} required value={reason} onChange={setReason} />
      </Modal>}
    </div>
  );
}

function ApplicationInterviews({ app }: { app: Application }) {
  const { rt } = useRecruitmentT();
  const source = useRecruitmentSource();
  const [scheduling, setScheduling] = useState(false);
  const list = useResource(useCallback((signal: AbortSignal) => source.interviews({ application_id: app.id, scope: 'all' }, signal), [source, app.id]));
  return (
    <div className="space-y-4 pt-4">
      {app.can.act && app.status === 'active' && <Button size="sm" onClick={() => setScheduling(true)}>{rt('scheduleInterview')}</Button>}
      <ResourceView errorLabel={rt('loadError')} resource={list} empty={rt('noInterviews')} isEmpty={(d) => d.length === 0}>
        {(items) => <ul className="space-y-3">{items.map((i) => <li key={i.id}><InterviewCard interview={i} onChanged={list.reload} /></li>)}</ul>}
      </ResourceView>
      {scheduling && <ScheduleDialog app={app} onClose={() => setScheduling(false)} onDone={() => { setScheduling(false); list.reload(); }} />}
    </div>
  );
}

function InterviewCard({ interview: i, onChanged }: { interview: Interview; onChanged: () => void }) {
  const { rt, when } = useRecruitmentT();
  const source = useRecruitmentSource();
  const { busy, run } = useAction();
  const [giving, setGiving] = useState(false);
  const [now] = useState(() => Date.now());
  const started = new Date(i.starts_at).getTime() <= now;
  return (
    <Card padding="md" title={`${i.title}${i.application?.candidate ? ` · ${i.application.candidate}` : ''}`} headingLevel={3}
      description={`${when(i.starts_at, i.timezone)} (${i.timezone}) · ${rt(`mode.${i.mode}`)}${i.location ? ` · ${i.location}` : ''}`}
      actions={<Badge size="sm" tone={i.status === 'cancelled' ? 'neutral' : i.status === 'completed' ? 'success' : 'accent'}>{rt(`interviewStatus.${i.status}`)}</Badge>}>
      <p className="text-sm text-fg-muted">{rt('panel')}: {i.panel.map((p) => `${p.name}${p.submitted ? ' ✓' : ''}`).join(', ')}</p>
      {i.feedback === null && i.is_panelist && !i.my_feedback && <p className="mt-1 text-xs text-fg-muted">{rt('feedbackHidden')}</p>}
      {(i.feedback ?? []).map((f) => <div key={f.id} className="mt-2 rounded-md border border-line p-2 text-sm"><div className="font-medium">{f.author} · {rt(`rec.${f.recommendation}`)} · {f.rating}/5</div>
        {f.strengths && <p>{rt('strengths')}: {f.strengths}</p>}{f.concerns && <p>{rt('concerns')}: {f.concerns}</p>}</div>)}
      <div className="mt-2 flex flex-wrap gap-2">
        {i.is_panelist && !i.my_feedback && i.status !== 'cancelled' && (started ? <Button size="sm" onClick={() => setGiving(true)}>{rt('giveFeedback')}</Button> : <span className="text-xs text-fg-muted">{rt('feedbackAfterStart')}</span>)}
        {i.status === 'scheduled' && !i.is_panelist && <Button size="sm" variant="tertiary" loading={busy === 'cancel'} onClick={() => run('cancel', () => source.interviewAction(i.id, 'cancel'), rt('saved')).then((ok) => ok && onChanged())}>{rt('cancelInterview')}</Button>}
      </div>
      {giving && <FeedbackDialog interview={i} onClose={() => setGiving(false)} onDone={() => { setGiving(false); onChanged(); }} />}
    </Card>
  );
}

function ScheduleDialog({ app, onClose, onDone }: { app: Application; onClose: () => void; onDone: () => void }) {
  const { rt, t } = useRecruitmentT();
  const source = useRecruitmentSource();
  const [search, setSearch] = useState('');
  const users = useResource(useCallback((signal: AbortSignal) => source.panelUsers(search, signal), [source, search]));
  const [input, setInput] = useState({ title: rt('defaultInterviewTitle'), starts_at: '', duration_minutes: 60, timezone: localTimeZone(), mode: 'video' as InterviewMode, location: '' });
  const [panel, setPanel] = useState<{ id: string; name: string }[]>([]);
  const { error, busy, save } = useSave();
  const fields = validationErrors(error);
  return (
    <Modal open onClose={onClose} size="lg" title={rt('scheduleInterview')} description={app.candidate?.name}
      footer={<><Button variant="secondary" onClick={onClose}>{t('ui.cancel')}</Button>
        <Button loading={busy} disabled={!input.starts_at || panel.length === 0} onClick={() => save(async () => { await source.schedule({ ...input, location: input.location || null, application_id: app.id, panel: panel.map((p) => p.id) }); onDone(); })}>{rt('schedule')}</Button></>}>
      <div className="grid gap-4 sm:grid-cols-2">
        <Input label={rt('interviewTitle')} required value={input.title} onChange={(e) => setInput({ ...input, title: e.target.value })} error={fields.title} />
        <Input label={rt('startsAt')} type="datetime-local" required value={input.starts_at} onChange={(e) => setInput({ ...input, starts_at: e.target.value })} error={fields.starts_at} />
        <Input label={rt('timezone')} required hint={rt('timezoneHint')} value={input.timezone} onChange={(e) => setInput({ ...input, timezone: e.target.value })} error={fields.timezone} />
        <Input label={rt('duration')} type="number" value={String(input.duration_minutes)} onChange={(e) => setInput({ ...input, duration_minutes: Number(e.target.value) })} error={fields.duration_minutes} />
        <Select label={rt('modeLabel')} value={input.mode} onChange={(e) => setInput({ ...input, mode: e.target.value as InterviewMode })} options={(['video', 'phone', 'onsite'] as const).map((m) => ({ value: m, label: rt(`mode.${m}`) }))} />
        <Input label={rt('locationOrLink')} value={input.location} onChange={(e) => setInput({ ...input, location: e.target.value })} error={fields.location} />
        <Input label={rt('findPanelist')} value={search} onChange={(e) => setSearch(e.target.value)} />
        <Select label={rt('addPanelist')} value="" placeholder={rt('choosePerson')} onChange={(e) => { const u = users.data?.find((x) => x.id === e.target.value); if (u && !panel.some((p) => p.id === u.id)) setPanel([...panel, u]); }}
          options={(users.data ?? []).map((u) => ({ value: u.id, label: u.name }))} error={fields.panel} />
        <div className="flex flex-wrap gap-2 sm:col-span-2">{panel.map((p) => <Button key={p.id} size="sm" variant="secondary" aria-label={rt('removePanelist', { name: p.name })} onClick={() => setPanel(panel.filter((x) => x.id !== p.id))}>{p.name} ×</Button>)}</div>
        <FormError message={Object.keys(fields).length ? null : error ? formError(error, rt('actionFailed')) : null} />
      </div>
    </Modal>
  );
}

function FeedbackDialog({ interview, onClose, onDone }: { interview: Interview; onClose: () => void; onDone: () => void }) {
  const { rt, t } = useRecruitmentT();
  const source = useRecruitmentSource();
  const [input, setInput] = useState<FeedbackInput>({ rating: 3, recommendation: 'yes', strengths: '', concerns: '', notes: '' });
  const { error, busy, save } = useSave();
  const fields = validationErrors(error);
  return (
    <Modal open onClose={onClose} title={rt('giveFeedback')} description={rt('feedbackHint')}
      footer={<><Button variant="secondary" onClick={onClose}>{t('ui.cancel')}</Button>
        <Button loading={busy} onClick={() => save(async () => { await source.submitFeedback(interview.id, { ...input, strengths: input.strengths || null, concerns: input.concerns || null, notes: input.notes || null }); onDone(); })}>{rt('submit')}</Button></>}>
      <div className="grid gap-4 sm:grid-cols-2">
        <Select label={rt('rating')} value={String(input.rating)} onChange={(e) => setInput({ ...input, rating: Number(e.target.value) })} options={[1, 2, 3, 4, 5].map((n) => ({ value: String(n), label: `${n}/5` }))} />
        <Select label={rt('recommendation')} value={input.recommendation} onChange={(e) => setInput({ ...input, recommendation: e.target.value as Recommendation })} options={RECS.map((r) => ({ value: r, label: rt(`rec.${r}`) }))} />
        <TextArea label={rt('strengths')} value={input.strengths ?? ''} onChange={(v) => setInput({ ...input, strengths: v })} error={fields.strengths} />
        <TextArea label={rt('concerns')} value={input.concerns ?? ''} onChange={(v) => setInput({ ...input, concerns: v })} error={fields.concerns} />
        <FormError message={Object.keys(fields).length ? null : error ? formError(error, rt('actionFailed')) : null} />
      </div>
    </Modal>
  );
}

function Offers({ app, onChanged }: { app: Application; onChanged: () => void }) {
  const { rt } = useRecruitmentT();
  const source = useRecruitmentSource();
  const { busy, run } = useAction();
  const [creating, setCreating] = useState(false);
  const [hiring, setHiring] = useState(false);
  const offers = useResource(useCallback((signal: AbortSignal) => source.offers(app.id, signal), [source, app.id]));
  const open = (offers.data ?? []).some((o) => o.next.length > 0);
  const accepted = (offers.data ?? []).some((o) => o.status === 'accepted');
  const act = (o: Offer, next: string) => run(`${o.id}${next}`, () => source.offerAction(o.id, OFFER_ACTIONS[next]!, null), rt('saved')).then((ok) => { if (ok) { offers.reload(); onChanged(); } });
  return (
    <div className="space-y-4 pt-4">
      <p className="text-sm text-fg-muted">{rt('offersHint')}</p>
      <div className="flex flex-wrap gap-2">
        {app.status === 'active' && !open && !accepted && <Button size="sm" onClick={() => setCreating(true)}>{rt('newOffer')}</Button>}
        {app.status === 'active' && accepted && app.can.manage && <Button size="sm" onClick={() => setHiring(true)}>{rt('hire')}</Button>}
      </div>
      <ResourceView errorLabel={rt('loadError')} resource={offers} empty={rt('noOffers')} isEmpty={(d) => d.length === 0}>
        {(list) => <ul className="space-y-3">{list.map((o) => (
          <li key={o.id}><Card padding="md" title={o.job_title} headingLevel={3} actions={<OfferStatusBadge status={o.status} />}
            description={`${new Intl.NumberFormat(undefined, { style: 'currency', currency: o.base_salary.currency }).format(Number(o.base_salary.amount))} · ${rt(`frequency.${o.pay_frequency}`)}${o.start_date ? ` · ${rt('startsOn', { date: o.start_date })}` : ''}`}>
            {o.approved_by && <p className="text-xs text-fg-muted">{rt('approvedBy', { name: o.approved_by })}</p>}
            <div className="mt-2 flex flex-wrap gap-2">{o.next.map((n) => <Button key={n} size="sm" variant={n === 'withdrawn' || n === 'declined' ? 'secondary' : 'primary'} loading={busy === `${o.id}${n}`} onClick={() => act(o, n)}>{rt(`offerAction.${OFFER_ACTIONS[n]}`)}</Button>)}</div>
          </Card></li>))}</ul>}
      </ResourceView>
      {creating && <OfferDialog app={app} onClose={() => setCreating(false)} onDone={() => { setCreating(false); offers.reload(); onChanged(); }} />}
      {hiring && <HireDialog app={app} onClose={() => setHiring(false)} onDone={() => { setHiring(false); onChanged(); }} />}
    </div>
  );
}

function OfferDialog({ app, onClose, onDone }: { app: Application; onClose: () => void; onDone: () => void }) {
  const { rt, t } = useRecruitmentT();
  const source = useRecruitmentSource();
  const [input, setInput] = useState({ job_title: app.opening?.title ?? '', base_salary: '', pay_frequency: 'annual' as const, start_date: '', expires_on: '', notes: '' });
  const { error, busy, save } = useSave();
  const fields = validationErrors(error);
  return (
    <Modal open onClose={onClose} title={rt('newOffer')} description={rt('offerHint')}
      footer={<><Button variant="secondary" onClick={onClose}>{t('ui.cancel')}</Button>
        <Button loading={busy} disabled={!input.base_salary} onClick={() => save(async () => { await source.createOffer(app.id, { ...input, start_date: input.start_date || null, expires_on: input.expires_on || null, notes: input.notes || null }); onDone(); })}>{rt('create')}</Button></>}>
      <div className="grid gap-4 sm:grid-cols-2">
        <Input label={rt('jobTitle')} required value={input.job_title} onChange={(e) => setInput({ ...input, job_title: e.target.value })} error={fields.job_title} />
        <Input label={rt('baseSalary')} required inputMode="decimal" hint={rt('baseSalaryHint')} value={input.base_salary} onChange={(e) => setInput({ ...input, base_salary: e.target.value })} error={fields.base_salary} />
        <Select label={rt('frequencyLabel')} value={input.pay_frequency} onChange={(e) => setInput({ ...input, pay_frequency: e.target.value as 'annual' })} options={(['annual', 'monthly', 'hourly'] as const).map((f) => ({ value: f, label: rt(`frequency.${f}`) }))} />
        <Input label={rt('startDate')} type="date" value={input.start_date} onChange={(e) => setInput({ ...input, start_date: e.target.value })} error={fields.start_date} />
        <Input label={rt('expiresOn')} type="date" value={input.expires_on} onChange={(e) => setInput({ ...input, expires_on: e.target.value })} error={fields.expires_on} />
        <FormError message={Object.keys(fields).length ? null : error ? formError(error, rt('actionFailed')) : null} />
      </div>
    </Modal>
  );
}

function HireDialog({ app, onClose, onDone }: { app: Application; onClose: () => void; onDone: () => void }) {
  const { rt, t } = useRecruitmentT();
  const source = useRecruitmentSource();
  const can = useCan();
  const templates = useResource(useCallback((signal: AbortSignal) => (can(P.OnboardingView) ? source.templates(signal) : Promise.resolve([])), [source])); // eslint-disable-line react-hooks/exhaustive-deps
  const [input, setInput] = useState({ employee_number: '', work_email: '', template_id: '' });
  const { error, busy, save } = useSave();
  const fields = validationErrors(error);
  return (
    <Modal open onClose={onClose} title={rt('hireTitle', { name: app.candidate?.name ?? '' })} description={rt('hireHint')}
      footer={<><Button variant="secondary" onClick={onClose}>{t('ui.cancel')}</Button>
        <Button loading={busy} disabled={!input.employee_number} onClick={() => save(async () => { await source.hire(app.id, { employee_number: input.employee_number, work_email: input.work_email || null, template_id: input.template_id || null, version: app.version }); onDone(); })}>{rt('hire')}</Button></>}>
      <div className="grid gap-4 sm:grid-cols-2">
        <Input label={rt('employeeNumber')} required value={input.employee_number} onChange={(e) => setInput({ ...input, employee_number: e.target.value })} error={fields.employee_number} />
        <Input label={rt('workEmail')} type="email" value={input.work_email} onChange={(e) => setInput({ ...input, work_email: e.target.value })} error={fields.work_email} />
        <Select label={rt('onboardingTemplate')} value={input.template_id} onChange={(e) => setInput({ ...input, template_id: e.target.value })} placeholder={rt('noOnboarding')}
          options={(templates.data ?? []).filter((x) => x.status === 'active').map((x) => ({ value: x.id, label: x.name }))} />
        <FormError message={Object.keys(fields).length ? null : error ? formError(error, rt('actionFailed')) : null} />
      </div>
    </Modal>
  );
}

/** AI drafts are labelled, stay pending until a person accepts or discards them, and never change the application. */
function AiAssist({ app, onChanged }: { app: Application; onChanged: () => void }) {
  const { rt, when } = useRecruitmentT();
  const source = useRecruitmentSource();
  const { busy, run } = useAction();
  const drafts = useResource(useCallback((signal: AbortSignal) => source.drafts(app.id, signal), [source, app.id]));
  return (
    <div className="space-y-4 pt-4">
      <p role="note" className="rounded-md border border-line bg-surface p-3 text-sm text-fg-muted">{rt('aiNote')}</p>
      <div className="flex flex-wrap gap-2">
        <Button size="sm" loading={busy === 'sum'} onClick={() => run('sum', () => source.summarize(app.id), rt('draftReady')).then((ok) => ok && drafts.reload())}>{rt('draftSummary')}</Button>
        <Button size="sm" variant="secondary" loading={busy === 'org'} onClick={() => run('org', () => source.organizeDocuments(app.id), rt('suggestionsReady')).then((ok) => ok && onChanged())}>{rt('suggestDocumentTypes')}</Button>
      </div>
      <ResourceView errorLabel={rt('loadError')} resource={drafts} empty={rt('noDrafts')} isEmpty={(d) => d.length === 0}>
        {(list) => <ul className="space-y-3">{list.map((d) => (
          <li key={d.id}><Card padding="md" title={rt('summaryDraft')} headingLevel={3} actions={<span className="flex gap-1"><AiBadge /><Badge size="sm" tone={d.status === 'accepted' ? 'success' : d.status === 'discarded' ? 'neutral' : 'warning'}>{rt(`draftStatus.${d.status}`)}</Badge></span>}
            description={d.created_at ? `${when(d.created_at)}${d.model ? ` · ${d.model}` : ''}` : undefined}>
            <p className="whitespace-pre-line text-sm text-fg">{d.content}</p>
            {d.requires_review && <div className="mt-2 flex gap-2">
              <Button size="sm" loading={busy === `a${d.id}`} onClick={() => run(`a${d.id}`, () => source.reviewDraft(d.id, 'accept'), rt('saved')).then((ok) => ok && drafts.reload())}>{rt('acceptDraft')}</Button>
              <Button size="sm" variant="secondary" loading={busy === `d${d.id}`} onClick={() => run(`d${d.id}`, () => source.reviewDraft(d.id, 'discard'), rt('saved')).then((ok) => ok && drafts.reload())}>{rt('discardDraft')}</Button>
            </div>}
          </Card></li>))}</ul>}
      </ResourceView>
    </div>
  );
}

function Openings() {
  const { rt } = useRecruitmentT();
  const source = useRecruitmentSource();
  const can = useCan();
  const { busy, run } = useAction();
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);
  const [editing, setEditing] = useState<Opening | 'new' | null>(null);
  const list = useResource(useCallback((signal: AbortSignal) => source.openings({ status, page }, signal), [source, status, page]));
  const action = (o: Opening, a: 'publish' | 'close') => run(`${a}${o.id}`, () => source.openingAction(o.id, a), rt(a === 'publish' ? 'published' : 'closedDone')).then((ok) => ok && list.reload());
  const columns: DataTableColumn<Opening>[] = [
    { id: 'title', header: rt('jobTitle'), primary: true, cell: (o) => <div><div className="font-medium text-fg">{o.title}</div><div className="text-xs text-fg-muted">{[o.department?.name, o.legal_entity?.name, rt(`workplace.${o.workplace}`)].filter(Boolean).join(' · ')}</div></div> },
    { id: 'status', header: rt('statusLabel'), cell: (o) => <OpeningStatusBadge status={o.status} /> },
    { id: 'apps', header: rt('candidates'), cell: (o) => o.active_count ?? o.applications_count ?? '—' },
    { id: 'manager', header: rt('hiringManager'), cell: (o) => o.hiring_manager?.name ?? '—' },
    { id: 'actions', header: rt('actions'), cell: (o) => can(P.RecruitmentManage) ? <div className="flex flex-wrap gap-1">
      {o.status !== 'closed' && <Button size="sm" variant="tertiary" onClick={() => setEditing(o)}>{rt('edit')}</Button>}
      {o.status === 'draft' && <Button size="sm" variant="tertiary" loading={busy === `publish${o.id}`} onClick={() => action(o, 'publish')}>{rt('publish')}</Button>}
      {o.status === 'published' && <Button size="sm" variant="tertiary" loading={busy === `close${o.id}`} onClick={() => action(o, 'close')}>{rt('close')}</Button>}
    </div> : null },
  ];
  return (
    <Card padding="none" title={rt('openings')} headingLevel={2} actions={<div className="flex flex-wrap items-end gap-2">
      <Select label={rt('statusLabel')} hideLabel value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }} placeholder={rt('allStatuses')}
        options={(['draft', 'published', 'closed'] as const).map((s) => ({ value: s, label: rt(`openingStatus.${s}`) }))} />
      {can(P.RecruitmentManage) && <Button size="sm" onClick={() => setEditing('new')}>{rt('newOpening')}</Button>}
    </div>}>
      {list.error instanceof ApiNotConnectedError ? <RecruitmentNotConnected /> : <>
        <DataTable caption={rt('openings')} hideCaption columns={columns} rows={list.data?.data ?? []} getRowId={(o) => o.id} loading={list.loading}
          error={list.error ? errorMessage(list.error, rt('loadError')) : null} onRetry={list.reload} empty={<EmptyState title={rt('noOpenings')} />} />
        <Pager meta={list.data?.meta} onPage={setPage} />
      </>}
      {editing && <OpeningDialog opening={editing === 'new' ? null : editing} onClose={() => setEditing(null)} onSaved={() => { setEditing(null); list.reload(); }} />}
    </Card>
  );
}

function OpeningDialog({ opening, onClose, onSaved }: { opening: Opening | null; onClose: () => void; onSaved: () => void }) {
  const { rt, t } = useRecruitmentT();
  const source = useRecruitmentSource();
  const entities = useResource(useCallback((signal: AbortSignal) => source.legalEntities(signal), [source]));
  const full = useResource(useCallback((signal: AbortSignal) => (opening ? source.opening(opening.id, signal) : Promise.resolve(null)), [source, opening]));
  const base = full.data ?? opening;
  const [input, setInput] = useState<OpeningInput | null>(null);
  const value: OpeningInput = input ?? { title: base?.title ?? '', legal_entity_id: base?.legal_entity?.id ?? '', workplace: base?.workplace ?? 'onsite', openings: base?.openings ?? 1,
    summary: base?.summary ?? '', description: base?.description ?? '', requirements: base?.requirements ?? '', closes_on: base?.closes_on ?? '', employment_type: base?.employment_type ?? '' };
  const loadingFull = opening !== null && !full.data;
  const set = (patch: Partial<OpeningInput>) => { if (!loadingFull) setInput({ ...value, ...patch }); };
  const { error, busy, save } = useSave();
  const fields = validationErrors(error);
  const payload = (): OpeningInput => ({ ...value, summary: value.summary || null, requirements: value.requirements || null, closes_on: value.closes_on || null, employment_type: value.employment_type || null,
    ...(opening ? { legal_entity_id: undefined } : {}) });
  return (
    <Modal open onClose={onClose} size="lg" title={opening ? rt('editOpening') : rt('newOpening')} description={rt('openingHint')}
      footer={<><Button variant="secondary" onClick={onClose}>{t('ui.cancel')}</Button>
        <Button loading={busy} disabled={loadingFull} onClick={() => save(async () => { await source.saveOpening(payload(), opening?.id); onSaved(); })}>{rt('save')}</Button></>}>
      {full.error != null && <p role="alert" className="mb-3 text-sm text-danger-fg">{errorMessage(full.error, rt('loadError'))}</p>}
      <fieldset disabled={loadingFull} className="grid gap-4 sm:grid-cols-2">
        <Input label={rt('jobTitle')} required value={value.title} onChange={(e) => set({ title: e.target.value })} error={fields.title} />
        <Select label={rt('legalEntity')} required disabled={opening !== null} value={value.legal_entity_id ?? ''} onChange={(e) => set({ legal_entity_id: e.target.value })} placeholder={rt('chooseEntity')} error={fields.legal_entity_id}
          options={(entities.data ?? []).map((e) => ({ value: e.id, label: `${e.name} (${e.country_code})` }))} />
        <Select label={rt('workplaceLabel')} value={value.workplace} onChange={(e) => set({ workplace: e.target.value as Workplace })} options={(['onsite', 'hybrid', 'remote'] as const).map((w) => ({ value: w, label: rt(`workplace.${w}`) }))} />
        <Input label={rt('employmentType')} hint={rt('employmentTypeHint')} value={value.employment_type ?? ''} onChange={(e) => set({ employment_type: e.target.value })} error={fields.employment_type} />
        <Input label={rt('positions')} type="number" value={String(value.openings)} onChange={(e) => set({ openings: Number(e.target.value) })} error={fields.openings} />
        <Input label={rt('closesOn')} type="date" value={value.closes_on ?? ''} onChange={(e) => set({ closes_on: e.target.value })} error={fields.closes_on} />
        <TextArea label={rt('summary')} value={value.summary ?? ''} onChange={(v) => set({ summary: v })} error={fields.summary} />
        <TextArea label={rt('description')} required value={value.description} onChange={(v) => set({ description: v })} error={fields.description} />
        <TextArea label={rt('requirements')} value={value.requirements ?? ''} onChange={(v) => set({ requirements: v })} error={fields.requirements} />
        <FormError message={Object.keys(fields).length ? null : error ? formError(error, rt('actionFailed')) : null} />
      </fieldset>
    </Modal>
  );
}

function MyInterviews() {
  const { rt } = useRecruitmentT();
  const source = useRecruitmentSource();
  const list = useResource(useCallback((signal: AbortSignal) => source.interviews({ scope: 'mine' }, signal), [source]));
  return (
    <Card title={rt('myInterviews')} headingLevel={2} description={rt('myInterviewsHint')}>
      {list.error instanceof ApiNotConnectedError ? <RecruitmentNotConnected /> : (
        <ResourceView errorLabel={rt('loadError')} resource={list} empty={rt('noInterviews')} isEmpty={(d) => d.length === 0}>
          {(items) => <ul className="space-y-3">{items.map((i) => <li key={i.id}><InterviewCard interview={i} onChanged={list.reload} /></li>)}</ul>}
        </ResourceView>
      )}
    </Card>
  );
}

function Settings() {
  const { rt } = useRecruitmentT();
  const source = useRecruitmentSource();
  const settings = useResource(useCallback((signal: AbortSignal) => source.settings(signal), [source]));
  return settings.error instanceof ApiNotConnectedError ? <RecruitmentNotConnected />
    : <ResourceView errorLabel={rt('loadError')} resource={settings} empty={rt('loadError')}>{(s) => <SettingsForm key={s.consent_version + s.stages.length} settings={s} onSaved={settings.reload} />}</ResourceView>;
}

function SettingsForm({ settings, onSaved }: { settings: RecruitmentSettings; onSaved: () => void }) {
  const { rt } = useRecruitmentT();
  const source = useRecruitmentSource();
  const { toast } = useToast();
  const { busy, run } = useAction();
  const [input, setInput] = useState({ careers_enabled: settings.careers_enabled, careers_slug: settings.careers_slug ?? '', careers_headline: settings.careers_headline ?? '', careers_intro: settings.careers_intro ?? '',
    retention_days: settings.retention_days, consent_text: settings.consent_text ?? '', ai_assist_enabled: settings.ai_assist_enabled });
  const [stage, setStage] = useState('');
  const { error, busy: saving, save } = useSave();
  const fields = validationErrors(error);
  return (
    <div className="space-y-6">
      <Card title={rt('careersSite')} headingLevel={2} description={settings.careers_slug ? rt('careersAddress', { path: `/careers/${settings.careers_slug}` }) : rt('careersSiteHint')}>
        <div className="grid gap-4 sm:grid-cols-2">
          <div className="sm:col-span-2"><Checkbox label={rt('careersEnabled')} checked={input.careers_enabled} onChange={(e) => setInput({ ...input, careers_enabled: e.target.checked })} /></div>
          <Input label={rt('careersSlug')} hint={rt('careersSlugHint')} value={input.careers_slug} onChange={(e) => setInput({ ...input, careers_slug: e.target.value.toLowerCase() })} error={fields.careers_slug ?? fields.careers_enabled} />
          <Input label={rt('careersHeadline')} value={input.careers_headline} onChange={(e) => setInput({ ...input, careers_headline: e.target.value })} error={fields.careers_headline} />
          <TextArea label={rt('careersIntro')} value={input.careers_intro} onChange={(v) => setInput({ ...input, careers_intro: v })} error={fields.careers_intro} />
          <TextArea label={rt('consentText')} required value={input.consent_text} onChange={(v) => setInput({ ...input, consent_text: v })} error={fields.consent_text} />
          <p className="text-xs text-fg-muted sm:col-span-2">{rt('consentVersionHint', { version: settings.consent_version })}</p>
          <Input label={rt('retentionDays')} type="number" hint={rt('retentionHint')} value={String(input.retention_days)} onChange={(e) => setInput({ ...input, retention_days: Number(e.target.value) })} error={fields.retention_days} />
          <div className="sm:col-span-2"><Checkbox label={rt('aiEnabled')} description={settings.ai_available ? rt('aiEnabledHint') : rt('aiUnavailable')} disabled={!settings.ai_available && !input.ai_assist_enabled}
            checked={input.ai_assist_enabled} onChange={(e) => setInput({ ...input, ai_assist_enabled: e.target.checked })} /></div>
          <FormError message={Object.keys(fields).length ? null : error ? formError(error, rt('actionFailed')) : null} />
          <div className="sm:col-span-2"><Button loading={saving} onClick={() => save(async () => {
            await source.saveSettings({ ...input, careers_slug: input.careers_slug || null, careers_headline: input.careers_headline || null, careers_intro: input.careers_intro || null });
            toast({ title: rt('saved'), tone: 'success' }); onSaved();
          })}>{rt('save')}</Button></div>
        </div>
      </Card>
      <Card title={rt('stages')} headingLevel={2} description={rt('stagesHint')}>
        <ul className="divide-y divide-line">{settings.stages.map((s) => (
          <li key={s.id} className="flex items-center justify-between gap-2 py-2"><span className="text-sm text-fg">{s.name}{s.kind !== 'active' && <Badge size="sm" variant="outline" className="ml-2">{rt(`stageKind.${s.kind}`)}</Badge>}</span>
            {s.kind === 'active' && <Button size="sm" variant="tertiary" loading={busy === s.id} onClick={() => run(s.id, () => source.removeStage(s.id), rt('removed')).then((ok) => ok && onSaved())}>{rt('remove')}</Button>}</li>))}
        </ul>
        <div className="mt-3 flex flex-wrap items-end gap-2">
          <Input label={rt('newStage')} value={stage} onChange={(e) => setStage(e.target.value)} />
          <Button size="sm" disabled={!stage.trim()} loading={busy === 'add'} onClick={() => run('add', () => source.addStage(stage.trim()), rt('saved')).then((ok) => { if (ok) { setStage(''); onSaved(); } })}>{rt('add')}</Button>
        </div>
      </Card>
    </div>
  );
}
