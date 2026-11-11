import { useCallback, useState } from 'react';
import { Permission as P } from '@wingora/permissions';
import type { MessageKey } from '@wingora/localization';
import { ApiNotConnectedError } from '@/api/dashboard';
import {
  useHelpdeskSource, type Article, type ArticleKind, type Attachment, type DueHours, type HelpdeskCategory, type Ref, type Ticket,
  type TicketPriority, type TicketQuery, type TicketStatus,
} from '@/api/helpdesk';
import { Badge, Button, Card, Checkbox, ConfirmationDialog, DataTable, Drawer, EmptyState, Input, Modal, Select, Tabs, useToast, type DataTableColumn } from '@/components/ui';
import { PageHeader } from '@/shell/page-header';
import { useSession } from '@/auth/session';
import { useI18n } from '@/i18n/i18n';
import { errorMessage, FormError, formError, ResourceView, useResource, validationErrors } from '@/modules/employees/shared';
import { Pager } from '@/modules/attendance';
import { TextArea } from '@/modules/time/shared';
import { FileField } from '@/modules/payroll/shared';
import { useAction, useCan, useSave } from '@/modules/recruitment/shared';

const PRIORITIES: TicketPriority[] = ['low', 'normal', 'high', 'urgent'];
const STATUSES: TicketStatus[] = ['open', 'in_progress', 'waiting', 'resolved', 'closed'];
const priorityTone = { low: 'neutral', normal: 'info', high: 'warning', urgent: 'danger' } as const;
const statusTone = { open: 'accent', in_progress: 'info', waiting: 'warning', resolved: 'success', closed: 'neutral' } as const;
const ACCEPT = '.pdf,.png,.jpg,.jpeg,.docx';

export function useHelpdeskT() {
  const { t, locale } = useI18n();
  const ht = (key: string, params?: Record<string, string | number>) => t(`helpdesk.${key}` as MessageKey, params);
  const when = (iso: string) => new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(iso));
  return { ht, t, when };
}

function PriorityBadge({ priority }: { priority: TicketPriority }) {
  const { ht } = useHelpdeskT();
  return <Badge size="sm" variant="outline" tone={priorityTone[priority]}>{ht(`priority.${priority}`)}</Badge>;
}
function StatusBadge({ status }: { status: TicketStatus }) {
  const { ht } = useHelpdeskT();
  return <Badge size="sm" dot tone={statusTone[status]}>{ht(`status.${status}`)}</Badge>;
}
function NotConnected() {
  const { ht } = useHelpdeskT();
  return <EmptyState title={ht('notConnectedTitle')} description={ht('notConnectedDescription')} />;
}
const fileError = (fields: Record<string, string>) => Object.entries(fields).find(([k]) => k.startsWith('files'))?.[1];
function FieldAlert({ message }: { message?: string }) {
  return message ? <p role="alert" className="text-sm text-danger-fg sm:col-span-2">{message}</p> : null;
}

export default function Helpdesk() {
  const { ht, t } = useHelpdeskT();
  const can = useCan();
  const staff = can(P.HelpdeskAgent, P.HelpdeskManage);
  const mine = can(P.HelpdeskSubmit) && { id: 'mine', label: ht('myTickets'), content: <TicketList scope="mine" /> };
  const tabs = [
    staff && { id: 'queue', label: ht('queue'), content: <TicketList scope="queue" /> },
    mine,
    can(P.HelpdeskSubmit, P.HelpdeskAgent, P.HelpdeskManage) && { id: 'kb', label: ht('knowledgeBase'), content: <KnowledgeBase /> },
    can(P.HelpdeskConfigure) && { id: 'settings', label: ht('settings'), content: <Settings /> },
  ].filter((x): x is { id: string; label: string; content: React.ReactElement } => Boolean(x));
  return (
    <>
      <PageHeader title={t('nav.module.helpdesk')} description={ht('subtitle')} />
      {tabs.length > 0 ? <Tabs label={t('nav.module.helpdesk')} tabs={tabs} /> : <EmptyState title={ht('nothingToShow')} />}
    </>
  );
}

function TicketList({ scope }: { scope: 'mine' | 'queue' }) {
  const { ht, when } = useHelpdeskT();
  const { toast } = useToast();
  const source = useHelpdeskSource();
  const [q, setQ] = useState<TicketQuery>({ page: 1, scope, status: 'active' });
  const [search, setSearch] = useState('');
  const [open, setOpen] = useState<string | null>(null);
  const [creating, setCreating] = useState(false);
  const tickets = useResource(useCallback((signal: AbortSignal) => source.tickets(q, signal), [source, q]));
  const set = (patch: Partial<TicketQuery>) => setQ({ ...q, ...patch, page: 1 });
  const columns = ([
    { id: 'subject', header: ht('subject'), primary: true, cell: (r) => (
      <button type="button" className="text-start font-medium text-fg-link hover:underline" onClick={() => setOpen(r.id)}>
        <span className="text-fg-muted tabular-nums">#{r.number}</span> {r.subject}
      </button>
    ) },
    { id: 'status', header: ht('statusLabel'), cell: (r) => <StatusBadge status={r.status} /> },
    { id: 'priority', header: ht('priorityLabel'), cell: (r) => <PriorityBadge priority={r.priority} /> },
    { id: 'category', header: ht('category'), hideBelow: 'md', cell: (r) => r.category.name },
    scope === 'queue' && { id: 'requester', header: ht('requester'), hideBelow: 'lg', cell: (r) => r.requester.name },
    scope === 'queue' && { id: 'assignee', header: ht('assignee'), hideBelow: 'md', cell: (r) => r.assignee?.name ?? ht('unassigned') },
    { id: 'due', header: ht('due'), hideBelow: 'lg', cell: (r) => r.due_at
      ? <span className="inline-flex flex-wrap items-center gap-1">{when(r.due_at)}{r.overdue && <Badge size="sm" tone="danger" variant="outline">{ht('overdue')}</Badge>}</span>
      : '—' },
  ] as (DataTableColumn<Ticket> | false)[]).filter((c): c is DataTableColumn<Ticket> => Boolean(c));

  return (
    <Card title={ht(scope === 'mine' ? 'myTickets' : 'queue')} headingLevel={2} padding="none" description={ht(scope === 'mine' ? 'myTicketsHint' : 'queueHint')}
      actions={scope === 'mine' && <Button onClick={() => setCreating(true)}>{ht('newTicket')}</Button>}>
      <form role="search" aria-label={ht('filters')} className="grid items-end gap-3 p-4 sm:grid-cols-2 lg:grid-cols-5" onSubmit={(e) => { e.preventDefault(); set({ q: search.trim() }); }}>
        <Input label={ht('search')} type="search" value={search} onChange={(e) => setSearch(e.target.value)} />
        <Select label={ht('statusLabel')} value={q.status ?? ''} onChange={(e) => set({ status: e.target.value as TicketQuery['status'] })}
          options={[{ value: 'active', label: ht('activeOnly') }, { value: '', label: ht('allStatuses') }, ...STATUSES.map((s) => ({ value: s, label: ht(`status.${s}`) }))]} />
        <Select label={ht('priorityLabel')} value={q.priority ?? ''} onChange={(e) => set({ priority: e.target.value as TicketQuery['priority'] })}
          options={[{ value: '', label: ht('allPriorities') }, ...PRIORITIES.map((p) => ({ value: p, label: ht(`priority.${p}`) }))]} />
        {scope === 'queue' && <Select label={ht('show')} value={q.scope ?? 'queue'} onChange={(e) => set({ scope: e.target.value as TicketQuery['scope'] })}
          options={[{ value: 'queue', label: ht('scopeQueue') }, { value: 'assigned', label: ht('scopeAssigned') }]} />}
        <div className="flex flex-wrap items-center gap-3">
          {scope === 'queue' && <Checkbox label={ht('overdueOnly')} checked={Boolean(q.overdue)} onChange={(e) => set({ overdue: e.target.checked })} />}
          <Button type="submit" variant="secondary">{ht('searchAction')}</Button>
        </div>
      </form>
      {tickets.error instanceof ApiNotConnectedError ? <div className="p-4"><NotConnected /></div> : (
        <>
          <DataTable caption={ht(scope === 'mine' ? 'myTickets' : 'queue')} hideCaption columns={columns} rows={tickets.data?.data ?? []} getRowId={(r) => r.id}
            loading={tickets.loading && tickets.data === undefined} error={tickets.error ? errorMessage(tickets.error, ht('loadError')) : null} onRetry={tickets.reload}
            empty={<EmptyState title={ht(scope === 'mine' ? 'noTickets' : 'noQueue')} />} />
          <Pager meta={tickets.data?.meta} onPage={(page) => setQ({ ...q, page })} />
        </>
      )}
      {creating && <NewTicketDialog onClose={() => setCreating(false)} onCreated={(t) => { setCreating(false); toast({ title: ht('created', { number: t.number }), tone: 'success' }); tickets.reload(); setOpen(t.id); }} />}
      {open && <TicketDrawer id={open} onClose={() => setOpen(null)} onChanged={tickets.reload} />}
    </Card>
  );
}

function NewTicketDialog({ onClose, onCreated }: { onClose: () => void; onCreated: (t: Ticket) => void }) {
  const { ht, t } = useHelpdeskT();
  const source = useHelpdeskSource();
  const categories = useResource(useCallback((signal: AbortSignal) => source.categories(signal), [source]));
  const [input, setInput] = useState({ category_id: '', subject: '', description: '', priority: 'normal' as TicketPriority });
  const [file, setFile] = useState<File | null>(null);
  const { error, busy, save } = useSave();
  const fields = validationErrors(error);
  const cats = (categories.data ?? []).filter((c) => c.active);
  const selected = cats.find((c) => c.id === input.category_id);
  const ready = input.category_id !== '' && input.subject.trim().length >= 3 && input.description.trim().length >= 3;
  return (
    <Modal open onClose={onClose} size="lg" title={ht('newTicket')} description={ht('newTicketHint')}
      footer={<><Button variant="secondary" onClick={onClose}>{t('ui.cancel')}</Button>
        <Button loading={busy} disabled={!ready} onClick={() => save(async () => onCreated(await source.createTicket({ ...input, files: file ? [file] : [] })))}>{ht('submit')}</Button></>}>
      <div className="grid gap-4 sm:grid-cols-2">
        <Select label={ht('category')} required value={input.category_id} placeholder={ht('chooseCategory')} error={fields.category_id}
          onChange={(e) => setInput({ ...input, category_id: e.target.value })} options={cats.map((c) => ({ value: c.id, label: c.name }))} />
        <Select label={ht('priorityLabel')} value={input.priority} error={fields.priority} onChange={(e) => setInput({ ...input, priority: e.target.value as TicketPriority })}
          options={PRIORITIES.map((p) => ({ value: p, label: ht(`priority.${p}`) }))} />
        {categories.error != null && <FieldAlert message={errorMessage(categories.error, ht('categoriesError'))} />}
        {selected?.description && <p className="text-sm text-fg-muted sm:col-span-2">{selected.description}</p>}
        {selected?.restricted && <p className="text-sm text-fg-muted sm:col-span-2">{ht('restrictedNotice')}</p>}
        <div className="sm:col-span-2"><Input label={ht('subject')} required maxLength={200} value={input.subject} error={fields.subject} onChange={(e) => setInput({ ...input, subject: e.target.value })} /></div>
        <TextArea label={ht('description')} required value={input.description} error={fields.description} onChange={(description) => setInput({ ...input, description })} />
        <FileField label={ht('attachmentOptional')} accept={ACCEPT} onFile={setFile} />
        <FieldAlert message={fileError(fields)} />
        <FormError message={error ? formError(error, ht('createFailed')) : null} />
      </div>
    </Modal>
  );
}

function TicketDrawer({ id, onClose, onChanged }: { id: string; onClose: () => void; onChanged: () => void }) {
  const { ht } = useHelpdeskT();
  const source = useHelpdeskSource();
  const ticket = useResource(useCallback((signal: AbortSignal) => source.ticket(id, signal), [source, id]));
  const tk = ticket.data;
  return (
    <Drawer open onClose={onClose} size="lg" title={tk ? `#${tk.number} · ${tk.subject}` : ht('ticket')} description={tk ? `${tk.category.name} · ${tk.requester.name}` : undefined}>
      <ResourceView resource={ticket} empty="" isEmpty={() => false} errorLabel={ht('loadError')}>
        {(data) => <TicketDetail ticket={data} onChanged={() => { ticket.reload(); onChanged(); }} />}
      </ResourceView>
    </Drawer>
  );
}

function Meta({ label, value, hint }: { label: string; value: string; hint?: string }) {
  return (
    <div>
      <dt className="text-xs font-medium text-fg-muted">{label}</dt>
      <dd className="text-sm text-fg">{value}{hint && <span className="block text-xs text-fg-muted">{hint}</span>}</dd>
    </div>
  );
}

function TicketDetail({ ticket, onChanged }: { ticket: Ticket; onChanged: () => void }) {
  const { ht, when } = useHelpdeskT();
  const source = useHelpdeskSource();
  const { busy, run } = useAction();
  // A failed change (for example a 409 version conflict) still reloads, so the next attempt uses fresh data.
  const act = (key: string, fn: () => Promise<unknown>, done: string) => run(key, fn, done).finally(onChanged);
  return (
    <div className="space-y-6">
      <div className="flex flex-wrap gap-2">
        <StatusBadge status={ticket.status} /><PriorityBadge priority={ticket.priority} />
        {ticket.category.restricted && <Badge size="sm" tone="warning" variant="outline">{ht('restricted')}</Badge>}
        {ticket.overdue && <Badge size="sm" tone="danger" variant="outline">{ht('overdue')}</Badge>}
      </div>
      <dl className="grid gap-3 sm:grid-cols-2">
        <Meta label={ht('requester')} value={ticket.requester.name} />
        <Meta label={ht('assignee')} value={ticket.assignee?.name ?? ht('unassigned')} />
        <Meta label={ht('opened')} value={ticket.created_at ? when(ticket.created_at) : '—'} />
        <Meta label={ht('due')} value={ticket.due_at ? when(ticket.due_at) : ht('noDue')} hint={ticket.due_at ? ht('dueHint') : undefined} />
      </dl>
      <section aria-labelledby="hd-description">
        <h3 id="hd-description" className="text-sm font-semibold text-fg">{ht('description')}</h3>
        <p className="mt-1 whitespace-pre-wrap break-words text-sm text-fg">{ticket.description}</p>
        <AttachmentList items={ticket.attachments ?? []} />
      </section>
      {(ticket.can.work || ticket.can.assign || ticket.can.take) && <StaffControls ticket={ticket} onChanged={onChanged} />}
      {ticket.next.length > 0 && (
        <div className="flex flex-wrap gap-2" role="group" aria-label={ht('changeStatus')}>
          {ticket.next.map((s) => (
            <Button key={s} size="sm" variant={s === 'resolved' ? 'primary' : 'secondary'} loading={busy === s} disabled={busy !== null}
              onClick={() => act(s, () => source.setStatus(ticket.id, s, ticket.version), ht('statusChanged', { status: ht(`status.${s}`) }))}>{ht(`moveTo.${s}`)}</Button>
          ))}
        </div>
      )}
      <Conversation ticket={ticket} onChanged={onChanged} />
    </div>
  );
}

function StaffControls({ ticket, onChanged }: { ticket: Ticket; onChanged: () => void }) {
  const { ht } = useHelpdeskT();
  const source = useHelpdeskSource();
  const { me } = useSession();
  const { busy, run } = useAction();
  const act = (key: string, fn: () => Promise<unknown>, done: string) => run(key, fn, done).finally(onChanged);
  const agents = useResource(useCallback((signal: AbortSignal) => (ticket.can.assign ? source.agents(signal) : Promise.resolve([] as Ref[])), [source, ticket.can.assign]));
  const categories = useResource(useCallback((signal: AbortSignal) => (ticket.can.work ? source.categories(signal) : Promise.resolve([] as HelpdeskCategory[])), [source, ticket.can.work]));
  const withCurrent = <T extends { id: string; name: string },>(list: T[], current: { id: string; name: string } | null) =>
    (current && !list.some((x) => x.id === current.id) ? [current, ...list] : list).map((x) => ({ value: x.id, label: x.name }));
  const meId = me?.user.id;
  return (
    <div className="grid gap-3 rounded-md border border-line p-3 sm:grid-cols-3">
      {ticket.can.work && <Select label={ht('priorityLabel')} value={ticket.priority} disabled={busy !== null}
        onChange={(e) => act('priority', () => source.updateTicket(ticket.id, { priority: e.target.value as TicketPriority }, ticket.version), ht('saved'))}
        options={PRIORITIES.map((p) => ({ value: p, label: ht(`priority.${p}`) }))} />}
      {ticket.can.work && <Select label={ht('category')} value={ticket.category.id} disabled={busy !== null || categories.loading}
        onChange={(e) => act('category', () => source.updateTicket(ticket.id, { category_id: e.target.value }, ticket.version), ht('saved'))}
        options={withCurrent((categories.data ?? []).filter((c) => c.active), ticket.category)} />}
      {ticket.can.assign ? (
        <Select label={ht('assignee')} value={ticket.assignee?.id ?? ''} disabled={busy !== null || agents.loading}
          onChange={(e) => act('assign', () => source.assign(ticket.id, e.target.value || null, ticket.version), ht('assignedToast'))}
          options={[{ value: '', label: ht('unassigned') }, ...withCurrent(agents.data ?? [], ticket.assignee)]} />
      ) : ticket.can.take && meId && ticket.assignee?.id !== meId && (
        <div className="flex items-end">
          <Button variant="secondary" loading={busy === 'take'} onClick={() => act('take', () => source.assign(ticket.id, meId, ticket.version), ht('assignedToast'))}>{ht('assignToMe')}</Button>
        </div>
      )}
    </div>
  );
}

function AttachmentList({ items }: { items: Attachment[] }) {
  const { ht } = useHelpdeskT();
  const source = useHelpdeskSource();
  const { busy, run } = useAction();
  if (items.length === 0) return null;
  return (
    <ul className="mt-2 flex flex-wrap gap-2" aria-label={ht('attachments')}>
      {items.map((a) => (
        <li key={a.id}>
          <Button size="sm" variant="secondary" loading={busy === a.id} onClick={() => run(a.id, () => source.downloadAttachment(a.id))}
            aria-label={ht('downloadNamed', { name: a.name ?? ht('attachment') })}>
            {a.name ?? ht('attachment')}{a.internal ? ` · ${ht('internalBadge')}` : ''}
          </Button>
        </li>
      ))}
    </ul>
  );
}

function Conversation({ ticket, onChanged }: { ticket: Ticket; onChanged: () => void }) {
  const { ht, when } = useHelpdeskT();
  const source = useHelpdeskSource();
  const { toast } = useToast();
  const [body, setBody] = useState('');
  const [internal, setInternal] = useState(false);
  const [file, setFile] = useState<File | null>(null);
  const [formKey, setFormKey] = useState(0);
  const { error, busy, save } = useSave();
  const fields = validationErrors(error);
  const comments = ticket.comments ?? [];
  const send = () => save(async () => {
    await source.comment(ticket.id, body, internal, file ? [file] : []);
    setBody('');
    setInternal(false);
    setFile(null);
    setFormKey((k) => k + 1);
    toast({ title: ht(internal ? 'noteAdded' : 'replySent'), tone: 'success' });
    onChanged();
  });
  return (
    <section aria-labelledby="hd-conversation">
      <h3 id="hd-conversation" className="text-sm font-semibold text-fg">{ht('conversation')}</h3>
      {comments.length === 0 ? <p className="mt-1 text-sm text-fg-muted">{ht('noComments')}</p> : (
        <ol className="mt-2 space-y-3">
          {comments.map((c) => (
            <li key={c.id} className={`rounded-md border p-3 ${c.internal ? 'border-warning-line bg-warning-subtle' : 'border-line'}`}>
              <div className="flex flex-wrap items-center gap-2 text-xs text-fg-muted">
                <span className="font-medium text-fg">{c.author.name}</span>
                {c.author.staff && <Badge size="sm">{ht('staffBadge')}</Badge>}
                {c.internal && <Badge size="sm" tone="warning">{ht('internalBadge')}</Badge>}
                {c.created_at && <time dateTime={c.created_at}>{when(c.created_at)}</time>}
              </div>
              <p className="mt-1 whitespace-pre-wrap break-words text-sm text-fg">{c.body}</p>
              <AttachmentList items={c.attachments} />
            </li>
          ))}
        </ol>
      )}
      {ticket.can.comment ? (
        <form key={formKey} className="mt-4 grid gap-3" onSubmit={(e) => { e.preventDefault(); void send(); }}>
          <TextArea label={ht(internal ? 'internalNote' : 'reply')} required value={body} onChange={setBody} error={fields.body} />
          {ticket.can.internal_notes && <Checkbox label={ht('internalNote')} description={ht('internalNoteHint')} checked={internal} onChange={(e) => setInternal(e.target.checked)} />}
          <FileField label={ht('attachmentOptional')} accept={ACCEPT} onFile={setFile} />
          <FieldAlert message={fileError(fields)} />
          <FormError message={error ? formError(error, ht('sendFailed')) : null} />
          <div><Button type="submit" loading={busy} disabled={body.trim() === ''}>{ht(internal ? 'addNote' : 'send')}</Button></div>
        </form>
      ) : ticket.status === 'closed' && <p className="mt-4 text-sm text-fg-muted">{ht('closedNotice')}</p>}
    </section>
  );
}

function KnowledgeBase() {
  const { ht } = useHelpdeskT();
  const can = useCan();
  const source = useHelpdeskSource();
  const manage = can(P.HelpdeskManage);
  const [q, setQ] = useState<{ page: number; kind?: ArticleKind; q: string }>({ page: 1, q: '' });
  const [search, setSearch] = useState('');
  const [reading, setReading] = useState<string | null>(null);
  const [editing, setEditing] = useState<Article | 'new' | null>(null);
  const [deleting, setDeleting] = useState<Article | null>(null);
  const articles = useResource(useCallback((signal: AbortSignal) => source.articles(q, signal), [source, q]));
  const { busy, run } = useAction();
  return (
    <Card title={ht('knowledgeBase')} headingLevel={2} description={ht('kbHint')} actions={manage && <Button onClick={() => setEditing('new')}>{ht('newArticle')}</Button>}>
      <form role="search" aria-label={ht('kbSearch')} className="mb-4 grid items-end gap-3 sm:grid-cols-3" onSubmit={(e) => { e.preventDefault(); setQ({ ...q, q: search.trim(), page: 1 }); }}>
        <Input label={ht('kbSearch')} type="search" value={search} onChange={(e) => setSearch(e.target.value)} />
        <Select label={ht('kindLabel')} value={q.kind ?? ''} onChange={(e) => setQ({ ...q, kind: (e.target.value || undefined) as ArticleKind | undefined, page: 1 })}
          options={[{ value: '', label: ht('allKinds') }, { value: 'faq', label: ht('kind.faq') }, { value: 'article', label: ht('kind.article') }]} />
        <div><Button type="submit" variant="secondary">{ht('searchAction')}</Button></div>
      </form>
      {articles.error instanceof ApiNotConnectedError ? <NotConnected /> : (
        <ResourceView resource={articles} empty={ht('noArticles')} isEmpty={(d) => d.data.length === 0} errorLabel={ht('loadError')}>
          {(page) => (
            <>
              <ul className="divide-y divide-line">
                {page.data.map((a) => (
                  <li key={a.id} className="py-3">
                    {a.kind === 'faq' ? (
                      <details>
                        <summary className="cursor-pointer font-medium text-fg">{a.title}</summary>
                        <p className="mt-2 whitespace-pre-wrap break-words text-sm text-fg-secondary">{a.body}</p>
                      </details>
                    ) : (
                      <div>
                        <button type="button" className="text-start font-medium text-fg-link hover:underline" onClick={() => setReading(a.id)}>{a.title}</button>
                        {a.summary && <p className="text-sm text-fg-muted">{a.summary}</p>}
                      </div>
                    )}
                    <div className="mt-1 flex flex-wrap items-center gap-2">
                      <Badge size="sm">{ht(`kind.${a.kind}`)}</Badge>
                      {a.category && <Badge size="sm" variant="outline">{a.category.name}</Badge>}
                      {a.status === 'draft' && <Badge size="sm" tone="warning">{ht('draft')}</Badge>}
                      {a.can.edit && (
                        <>
                          <Button size="sm" variant="tertiary" onClick={() => setEditing(a)} aria-label={ht('editNamed', { title: a.title })}>{ht('edit')}</Button>
                          <Button size="sm" variant="tertiary" loading={busy === `p${a.id}`} aria-label={ht(a.status === 'draft' ? 'publishNamed' : 'unpublishNamed', { title: a.title })}
                            onClick={() => run(`p${a.id}`, () => source.publishArticle(a.id, a.status === 'draft'), ht(a.status === 'draft' ? 'publishedToast' : 'unpublishedToast')).then((ok) => ok && articles.reload())}>
                            {ht(a.status === 'draft' ? 'publish' : 'unpublish')}
                          </Button>
                          <Button size="sm" variant="tertiary" onClick={() => setDeleting(a)} aria-label={ht('deleteNamed', { title: a.title })}>{ht('delete')}</Button>
                        </>
                      )}
                    </div>
                  </li>
                ))}
              </ul>
              <Pager meta={page.meta} onPage={(p) => setQ({ ...q, page: p })} />
            </>
          )}
        </ResourceView>
      )}
      {reading && <ArticleDrawer id={reading} onClose={() => setReading(null)} />}
      {editing && <ArticleDialog article={editing === 'new' ? null : editing} onClose={() => setEditing(null)} onSaved={() => { setEditing(null); articles.reload(); }} />}
      <ConfirmationDialog open={deleting !== null} tone="danger" title={ht('deleteTitle')} description={ht('deleteHint', { title: deleting?.title ?? '' })} confirmLabel={ht('delete')}
        onCancel={() => setDeleting(null)}
        onConfirm={async () => { const a = deleting; setDeleting(null); if (a && await run('delete', () => source.deleteArticle(a.id), ht('deleted'))) articles.reload(); }} />
    </Card>
  );
}

function ArticleDrawer({ id, onClose }: { id: string; onClose: () => void }) {
  const { ht, when } = useHelpdeskT();
  const source = useHelpdeskSource();
  const article = useResource(useCallback((signal: AbortSignal) => source.article(id, signal), [source, id]));
  return (
    <Drawer open onClose={onClose} size="lg" title={article.data?.title ?? ht('kind.article')} description={article.data?.summary ?? undefined}>
      <ResourceView resource={article} empty="" isEmpty={() => false} errorLabel={ht('loadError')}>
        {(a) => (
          <article className="space-y-3">
            {a.updated_at && <p className="text-xs text-fg-muted">{ht('updated', { date: when(a.updated_at) })}</p>}
            <p className="whitespace-pre-wrap break-words text-sm text-fg">{a.body}</p>
          </article>
        )}
      </ResourceView>
    </Drawer>
  );
}

function ArticleDialog({ article, onClose, onSaved }: { article: Article | null; onClose: () => void; onSaved: () => void }) {
  const { ht } = useHelpdeskT();
  return (
    <Modal open onClose={onClose} size="lg" title={ht(article ? 'editArticle' : 'newArticle')}>
      {article ? <EditArticle id={article.id} onClose={onClose} onSaved={onSaved} /> : <ArticleForm initial={null} onClose={onClose} onSaved={onSaved} />}
    </Modal>
  );
}

/** Waits for the full article so typing can never start on a blank body that would then overwrite it. */
function EditArticle({ id, onClose, onSaved }: { id: string; onClose: () => void; onSaved: () => void }) {
  const { ht } = useHelpdeskT();
  const source = useHelpdeskSource();
  const article = useResource(useCallback((signal: AbortSignal) => source.article(id, signal), [source, id]));
  return (
    <ResourceView resource={article} empty="" isEmpty={() => false} errorLabel={ht('loadError')}>
      {(a) => <ArticleForm initial={a} onClose={onClose} onSaved={onSaved} />}
    </ResourceView>
  );
}

function ArticleForm({ initial, onClose, onSaved }: { initial: Article | null; onClose: () => void; onSaved: () => void }) {
  const { ht, t } = useHelpdeskT();
  const source = useHelpdeskSource();
  const { toast } = useToast();
  const categories = useResource(useCallback((signal: AbortSignal) => source.categories(signal), [source]));
  const [input, setInput] = useState({
    kind: initial?.kind ?? ('faq' as ArticleKind), title: initial?.title ?? '', summary: initial?.summary ?? '', body: initial?.body ?? '', category_id: initial?.category?.id ?? '',
  });
  const { error, busy, save } = useSave();
  const fields = validationErrors(error);
  return (
    <form className="grid gap-4 sm:grid-cols-2" onSubmit={(e) => {
      e.preventDefault();
      void save(async () => {
        await source.saveArticle({ ...input, summary: input.summary.trim() || null, category_id: input.category_id || null }, initial?.id);
        toast({ title: ht('saved'), tone: 'success' });
        onSaved();
      });
    }}>
      <Select label={ht('kindLabel')} value={input.kind} onChange={(e) => setInput({ ...input, kind: e.target.value as ArticleKind })}
        options={[{ value: 'faq', label: ht('kind.faq') }, { value: 'article', label: ht('kind.article') }]} />
      <Select label={ht('categoryOptional')} value={input.category_id} onChange={(e) => setInput({ ...input, category_id: e.target.value })} error={fields.category_id}
        options={[{ value: '', label: ht('noCategory') }, ...(categories.data ?? []).map((c) => ({ value: c.id, label: c.name }))]} />
      <div className="sm:col-span-2"><Input label={ht('title')} required maxLength={200} value={input.title} error={fields.title} onChange={(e) => setInput({ ...input, title: e.target.value })} /></div>
      <div className="sm:col-span-2"><Input label={ht('summary')} maxLength={1000} value={input.summary} error={fields.summary} onChange={(e) => setInput({ ...input, summary: e.target.value })} /></div>
      <TextArea label={ht(input.kind === 'faq' ? 'answer' : 'body')} required value={input.body} error={fields.body} onChange={(body) => setInput({ ...input, body })} />
      {!initial && <p className="text-sm text-fg-muted sm:col-span-2">{ht('draftNotice')}</p>}
      <FormError message={error ? formError(error, ht('saveFailed')) : null} />
      <div className="flex justify-end gap-2 sm:col-span-2">
        <Button variant="secondary" onClick={onClose}>{t('ui.cancel')}</Button>
        <Button type="submit" loading={busy} disabled={input.title.trim().length < 3 || input.body.trim().length < 3}>{ht('save')}</Button>
      </div>
    </form>
  );
}

function Settings() {
  return <div className="space-y-6"><DueTargets /><Categories /></div>;
}

function DueTargets() {
  const { ht } = useHelpdeskT();
  const source = useHelpdeskSource();
  const settings = useResource(useCallback((signal: AbortSignal) => source.settings(signal), [source]));
  return (
    <Card title={ht('dueTargets')} headingLevel={2} description={ht('dueTargetsHint')}>
      {settings.error instanceof ApiNotConnectedError ? <NotConnected /> : (
        <ResourceView resource={settings} empty="" isEmpty={() => false} errorLabel={ht('loadError')}>
          {(d) => <DueForm initial={d.due_hours} onSaved={settings.reload} />}
        </ResourceView>
      )}
    </Card>
  );
}

function DueForm({ initial, onSaved }: { initial: DueHours; onSaved: () => void }) {
  const { ht } = useHelpdeskT();
  const source = useHelpdeskSource();
  const { toast } = useToast();
  const [hours, setHours] = useState<Record<TicketPriority, string>>(
    Object.fromEntries(PRIORITIES.map((p) => [p, initial[p] == null ? '' : String(initial[p])])) as Record<TicketPriority, string>,
  );
  const { error, busy, save } = useSave();
  const fields = validationErrors(error);
  return (
    <form className="grid items-end gap-4 sm:grid-cols-2 lg:grid-cols-5" onSubmit={(e) => {
      e.preventDefault();
      void save(async () => {
        await source.saveSettings(Object.fromEntries(PRIORITIES.map((p) => [p, hours[p] === '' ? null : Number(hours[p])])) as DueHours);
        toast({ title: ht('saved'), tone: 'success' });
        onSaved();
      });
    }}>
      {PRIORITIES.map((p) => (
        <Input key={p} label={ht('hoursFor', { priority: ht(`priority.${p}`) })} type="number" min={1} max={2160} inputMode="numeric" value={hours[p]}
          error={fields[`due_hours.${p}`]} onChange={(e) => setHours({ ...hours, [p]: e.target.value })} />
      ))}
      <div><Button type="submit" loading={busy}>{ht('save')}</Button></div>
      <FormError message={error ? formError(error, ht('saveFailed')) : null} />
    </form>
  );
}

function Categories() {
  const { ht } = useHelpdeskT();
  const source = useHelpdeskSource();
  const categories = useResource(useCallback((signal: AbortSignal) => source.categories(signal), [source]));
  const [editing, setEditing] = useState<HelpdeskCategory | 'new' | null>(null);
  const columns: DataTableColumn<HelpdeskCategory>[] = [
    { id: 'name', header: ht('name'), primary: true, cell: (c) => <div><span className="font-medium text-fg">{c.name}</span>{c.description && <span className="block text-xs text-fg-muted">{c.description}</span>}</div> },
    { id: 'access', header: ht('access'), cell: (c) => c.restricted ? <Badge size="sm" tone="warning" variant="outline">{ht('restricted')}</Badge> : <Badge size="sm">{ht('allAgents')}</Badge> },
    { id: 'agents', header: ht('agents'), hideBelow: 'md', cell: (c) => (c.agents?.length ? c.agents.map((a) => a.name).join(', ') : '—') },
    { id: 'active', header: ht('statusLabel'), cell: (c) => <Badge size="sm" dot tone={c.active ? 'success' : 'neutral'}>{ht(c.active ? 'active' : 'inactive')}</Badge> },
    { id: 'edit', header: <span className="sr-only">{ht('edit')}</span>, align: 'end', cell: (c) => <Button size="sm" variant="tertiary" onClick={() => setEditing(c)} aria-label={ht('editNamed', { title: c.name })}>{ht('edit')}</Button> },
  ];
  return (
    <Card title={ht('categories')} headingLevel={2} padding="none" description={ht('categoriesHint')} actions={<Button onClick={() => setEditing('new')}>{ht('newCategory')}</Button>}>
      {categories.error instanceof ApiNotConnectedError ? <div className="p-4"><NotConnected /></div> : (
        <DataTable caption={ht('categories')} hideCaption columns={columns} rows={categories.data ?? []} getRowId={(c) => c.id}
          loading={categories.loading && categories.data === undefined} error={categories.error ? errorMessage(categories.error, ht('loadError')) : null} onRetry={categories.reload}
          empty={<EmptyState title={ht('noCategories')} />} />
      )}
      {editing && <CategoryDialog category={editing === 'new' ? null : editing} onClose={() => setEditing(null)} onSaved={() => { setEditing(null); categories.reload(); }} />}
    </Card>
  );
}

function CategoryDialog({ category, onClose, onSaved }: { category: HelpdeskCategory | null; onClose: () => void; onSaved: () => void }) {
  const { ht, t } = useHelpdeskT();
  const source = useHelpdeskSource();
  const { toast } = useToast();
  const agents = useResource(useCallback((signal: AbortSignal) => source.agents(signal), [source]));
  const [input, setInput] = useState({
    name: category?.name ?? '', description: category?.description ?? '', restricted: category?.restricted ?? false, active: category?.active ?? true,
    agent_ids: category?.agents?.map((a) => a.id) ?? [],
  });
  const { error, busy, save } = useSave();
  const fields = validationErrors(error);
  const toggle = (id: string, on: boolean) => setInput({ ...input, agent_ids: on ? [...input.agent_ids, id] : input.agent_ids.filter((x) => x !== id) });
  const people = [...(agents.data ?? []), ...(category?.agents ?? []).filter((a) => !(agents.data ?? []).some((x) => x.id === a.id))];
  return (
    <Modal open onClose={onClose} size="lg" title={ht(category ? 'editCategory' : 'newCategory')}
      footer={<><Button variant="secondary" onClick={onClose}>{t('ui.cancel')}</Button>
        <Button loading={busy} disabled={input.name.trim().length < 2} onClick={() => save(async () => {
          await source.saveCategory({ ...input, description: input.description.trim() || null }, category?.id);
          toast({ title: ht('saved'), tone: 'success' });
          onSaved();
        })}>{ht('save')}</Button></>}>
      <div className="grid gap-4 sm:grid-cols-2">
        <div className="sm:col-span-2"><Input label={ht('name')} required maxLength={80} value={input.name} error={fields.name} onChange={(e) => setInput({ ...input, name: e.target.value })} /></div>
        <TextArea label={ht('descriptionOptional')} value={input.description} error={fields.description} onChange={(description) => setInput({ ...input, description })} />
        <Checkbox label={ht('restrictedLabel')} description={ht('restrictedHint')} checked={input.restricted} onChange={(e) => setInput({ ...input, restricted: e.target.checked })} />
        <Checkbox label={ht('activeLabel')} description={ht('activeHint')} checked={input.active} onChange={(e) => setInput({ ...input, active: e.target.checked })} />
        <fieldset className="sm:col-span-2">
          <legend className="text-sm font-medium text-fg">{ht('agents')}</legend>
          <p className="text-xs text-fg-muted">{ht('agentsHint')}</p>
          {agents.error != null ? <FieldAlert message={errorMessage(agents.error, ht('agentsError'))} />
            : people.length === 0 ? <p className="mt-2 text-sm text-fg-muted">{agents.loading ? ht('loadingAgents') : ht('noAgents')}</p>
              : <div className="mt-2 grid max-h-56 gap-2 overflow-y-auto sm:grid-cols-2">{people.map((a) => <Checkbox key={a.id} label={a.name} checked={input.agent_ids.includes(a.id)} onChange={(e) => toggle(a.id, e.target.checked)} />)}</div>}
          <FieldAlert message={fields.agent_ids} />
        </fieldset>
        <FormError message={error ? formError(error, ht('saveFailed')) : null} />
      </div>
    </Modal>
  );
}
