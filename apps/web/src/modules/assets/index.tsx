import { useCallback, useState } from 'react';
import { Permission as P } from '@wingora/permissions';
import type { MessageKey } from '@wingora/localization';
import { ApiNotConnectedError } from '@/api/dashboard';
import {
  useAssetsSource, type Asset, type AssetCategory, type AssetCondition, type AssetInput, type AssetQuery, type AssetStatus, type Assignment,
  type MaintenanceInput, type MaintenanceKind, type MaintenanceRecord, type MaintenanceStatus,
} from '@/api/assets';
import { emptyEmployeeQuery, useEmployeeSource } from '@/api/employees';
import { Badge, Button, Card, Checkbox, ConfirmationDialog, DataTable, Drawer, EmptyState, Input, Modal, Select, Tabs, useToast, type DataTableColumn } from '@/components/ui';
import { PageHeader } from '@/shell/page-header';
import { useI18n } from '@/i18n/i18n';
import { errorMessage, FormError, formError, ResourceView, useResource, validationErrors } from '@/modules/employees/shared';
import { Pager } from '@/modules/attendance';
import { TextArea } from '@/modules/time/shared';
import { useAction, useCan, useSave } from '@/modules/recruitment/shared';

const STATUSES: AssetStatus[] = ['available', 'assigned', 'in_maintenance', 'retired'];
const CONDITIONS: AssetCondition[] = ['new', 'good', 'fair', 'poor', 'damaged'];
const KINDS: MaintenanceKind[] = ['repair', 'service', 'inspection', 'upgrade'];
const CURRENCIES = ['USD', 'AED', 'CAD', 'MXN'];
const statusTone = { available: 'success', assigned: 'accent', in_maintenance: 'warning', retired: 'neutral' } as const;
const workTone = { scheduled: 'neutral', in_progress: 'warning', completed: 'success', cancelled: 'neutral' } as const;
const NEXT: Record<MaintenanceStatus, MaintenanceStatus[]> = { scheduled: ['in_progress', 'completed', 'cancelled'], in_progress: ['completed', 'cancelled'], completed: [], cancelled: [] };

export function useAssetsT() {
  const { t, locale } = useI18n();
  const at = (key: string, params?: Record<string, string | number>) => t(`assets.${key}` as MessageKey, params);
  const day = (iso: string | null) => (iso ? new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeZone: 'UTC' }).format(new Date(`${iso}T00:00:00Z`)) : '—');
  const money = (amount: string | null, currency: string | null) => (amount && currency ? new Intl.NumberFormat(locale, { style: 'currency', currency }).format(Number(amount)) : '—');
  return { at, t, day, money };
}
const blank = (v: string) => (v.trim() === '' ? null : v.trim());

function StatusBadge({ status }: { status: AssetStatus }) {
  const { at } = useAssetsT();
  return <Badge size="sm" dot tone={statusTone[status]}>{at(`status.${status}`)}</Badge>;
}
function NotConnected() {
  const { at } = useAssetsT();
  return <EmptyState title={at('notConnectedTitle')} description={at('notConnectedDescription')} />;
}
function Meta({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <dt className="text-xs font-medium text-fg-muted">{label}</dt>
      <dd className="whitespace-pre-line text-sm text-fg">{value}</dd>
    </div>
  );
}

export default function Assets() {
  const { at, t } = useAssetsT();
  const can = useCan();
  const tabs = [
    can(P.AssetsView, P.AssetsManage) && { id: 'mine', label: at('myAssets'), content: <MyAssets /> },
    can(P.AssetsManage) && { id: 'registry', label: at('registry'), content: <Registry /> },
    can(P.AssetsManage) && { id: 'categories', label: at('categories'), content: <Categories /> },
  ].filter((x): x is { id: string; label: string; content: React.ReactElement } => Boolean(x));
  return (
    <>
      <PageHeader title={t('nav.module.assets')} description={at('subtitle')} />
      {tabs.length > 0 ? <Tabs label={t('nav.module.assets')} tabs={tabs} /> : <EmptyState title={at('nothingToShow')} />}
    </>
  );
}

function MyAssets() {
  const { at, day } = useAssetsT();
  const source = useAssetsSource();
  const [page, setPage] = useState(1);
  const mine = useResource(useCallback((signal: AbortSignal) => source.mine(page, signal), [source, page]));
  const columns: DataTableColumn<Assignment>[] = [
    { id: 'asset', header: at('asset'), primary: true, cell: (a) => <span className="font-medium"><span className="text-fg-muted">{a.asset?.asset_tag}</span> {a.asset?.name}</span> },
    { id: 'category', header: at('category'), hideBelow: 'md', cell: (a) => a.asset?.category ?? '—' },
    { id: 'assigned', header: at('assignedOn'), cell: (a) => day(a.assigned_on) },
    { id: 'due', header: at('dueBack'), hideBelow: 'md', cell: (a) => day(a.due_back_on) },
    { id: 'state', header: at('statusLabel'), cell: (a) => (a.returned_on ? <Badge size="sm" tone="neutral">{at('returnedOn', { date: day(a.returned_on) })}</Badge> : <Badge size="sm" dot tone="accent">{at('withYou')}</Badge>) },
  ];
  return (
    <Card title={at('myAssets')} headingLevel={2} padding="none" description={at('myAssetsHint')}>
      {mine.error instanceof ApiNotConnectedError ? <div className="p-4"><NotConnected /></div> : (
        <>
          <DataTable caption={at('myAssets')} hideCaption columns={columns} rows={mine.data?.data ?? []} getRowId={(a) => a.id}
            loading={mine.loading && mine.data === undefined} error={mine.error ? errorMessage(mine.error, at('loadError')) : null} onRetry={mine.reload}
            empty={<EmptyState title={at('noAssignments')} />} />
          <Pager meta={mine.data?.meta} onPage={setPage} />
        </>
      )}
    </Card>
  );
}

function Registry() {
  const { at } = useAssetsT();
  const { toast } = useToast();
  const source = useAssetsSource();
  const [q, setQ] = useState<AssetQuery>({ page: 1, status: '' });
  const [search, setSearch] = useState('');
  const [open, setOpen] = useState<string | null>(null);
  const [creating, setCreating] = useState(false);
  const assets = useResource(useCallback((signal: AbortSignal) => source.assets(q, signal), [source, q]));
  const categories = useResource(useCallback((signal: AbortSignal) => source.categories(signal), [source]));
  const set = (patch: Partial<AssetQuery>) => setQ({ ...q, ...patch, page: 1 });
  const columns: DataTableColumn<Asset>[] = [
    { id: 'asset', header: at('asset'), primary: true, cell: (a) => (
      <button type="button" className="text-start font-medium text-fg-link hover:underline" onClick={() => setOpen(a.id)}>
        <span className="text-fg-muted">{a.asset_tag}</span> {a.name}
      </button>
    ) },
    { id: 'category', header: at('category'), hideBelow: 'md', cell: (a) => a.category?.name ?? '—' },
    { id: 'status', header: at('statusLabel'), cell: (a) => <StatusBadge status={a.status} /> },
    { id: 'assignee', header: at('assignedTo'), cell: (a) => a.assigned_to?.name ?? '—' },
    { id: 'condition', header: at('condition'), hideBelow: 'lg', cell: (a) => at(`conditions.${a.condition}`) },
  ];
  return (
    <Card title={at('registry')} headingLevel={2} padding="none" description={at('registryHint')} actions={<Button onClick={() => setCreating(true)}>{at('newAsset')}</Button>}>
      <form role="search" aria-label={at('filters')} className="grid items-end gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4" onSubmit={(e) => { e.preventDefault(); set({ q: search.trim() }); }}>
        <Input label={at('search')} type="search" placeholder={at('searchHint')} value={search} onChange={(e) => setSearch(e.target.value)} />
        <Select label={at('statusLabel')} value={q.status ?? ''} onChange={(e) => set({ status: e.target.value as AssetQuery['status'] })}
          options={[{ value: '', label: at('allStatuses') }, ...STATUSES.map((s) => ({ value: s, label: at(`status.${s}`) }))]} />
        <Select label={at('category')} value={q.category ?? ''} onChange={(e) => set({ category: e.target.value })}
          options={[{ value: '', label: at('allCategories') }, ...(categories.data ?? []).map((c) => ({ value: c.id, label: c.name }))]} />
        <div><Button type="submit" variant="secondary">{at('searchAction')}</Button></div>
      </form>
      {assets.error instanceof ApiNotConnectedError ? <div className="p-4"><NotConnected /></div> : (
        <>
          <DataTable caption={at('registry')} hideCaption columns={columns} rows={assets.data?.data ?? []} getRowId={(a) => a.id}
            loading={assets.loading && assets.data === undefined} error={assets.error ? errorMessage(assets.error, at('loadError')) : null} onRetry={assets.reload}
            empty={<EmptyState title={at('noAssets')} />} />
          <Pager meta={assets.data?.meta} onPage={(page) => setQ({ ...q, page })} />
        </>
      )}
      {creating && <AssetForm categories={categories.data ?? []} onClose={() => setCreating(false)} onSaved={(a) => { setCreating(false); toast({ title: at('saved'), tone: 'success' }); assets.reload(); setOpen(a.id); }} />}
      {open && <AssetDrawer id={open} categories={categories.data ?? []} onClose={() => setOpen(null)} onChanged={assets.reload} />}
    </Card>
  );
}

function AssetForm({ asset, categories, onClose, onSaved }: { asset?: Asset; categories: AssetCategory[]; onClose: () => void; onSaved: (a: Asset) => void }) {
  const { at, t } = useAssetsT();
  const source = useAssetsSource();
  const { error, busy, save } = useSave();
  const fields = validationErrors(error);
  const [f, setF] = useState({
    category_id: asset?.category?.id ?? '', asset_tag: asset?.asset_tag ?? '', name: asset?.name ?? '', serial_number: asset?.serial_number ?? '',
    condition: asset?.condition ?? 'good', purchased_on: asset?.purchased_on ?? '', purchase_cost: asset?.purchase_cost ?? '', currency: asset?.currency ?? '',
    warranty_ends_on: asset?.warranty_ends_on ?? '', notes: asset?.notes ?? '',
  });
  const set = (patch: Partial<typeof f>) => setF({ ...f, ...patch });
  const options = categories.filter((c) => c.active || c.id === asset?.category?.id).map((c) => ({ value: c.id, label: c.name }));
  const submit = () => save(async () => {
    const input: AssetInput = {
      category_id: f.category_id, asset_tag: f.asset_tag.trim(), name: f.name.trim(), serial_number: blank(f.serial_number), condition: f.condition as AssetCondition,
      purchased_on: blank(f.purchased_on), purchase_cost: blank(f.purchase_cost), currency: blank(f.currency), warranty_ends_on: blank(f.warranty_ends_on), notes: blank(f.notes),
    };
    onSaved(await source.saveAsset(input, asset?.id, asset?.version));
  });
  return (
    <Modal open onClose={onClose} size="lg" title={asset ? at('editAsset') : at('newAsset')}
      footer={<><Button variant="secondary" onClick={onClose}>{t('ui.cancel')}</Button><Button loading={busy} disabled={!f.category_id || !f.asset_tag.trim() || !f.name.trim()} onClick={submit}>{at('save')}</Button></>}>
      <div className="grid gap-4 sm:grid-cols-2">
        <Input label={at('assetTag')} required maxLength={40} value={f.asset_tag} error={fields.asset_tag} onChange={(e) => set({ asset_tag: e.target.value })} />
        <Input label={at('name')} required maxLength={120} value={f.name} error={fields.name} onChange={(e) => set({ name: e.target.value })} />
        <Select label={at('category')} required placeholder={at('chooseCategory')} value={f.category_id} error={fields.category_id} onChange={(e) => set({ category_id: e.target.value })} options={options} />
        <Input label={at('serialNumber')} maxLength={120} value={f.serial_number} error={fields.serial_number} onChange={(e) => set({ serial_number: e.target.value })} />
        <Select label={at('condition')} value={f.condition} onChange={(e) => set({ condition: e.target.value as AssetCondition })} options={CONDITIONS.map((c) => ({ value: c, label: at(`conditions.${c}`) }))} />
        <Input label={at('purchasedOn')} type="date" value={f.purchased_on} error={fields.purchased_on} onChange={(e) => set({ purchased_on: e.target.value })} />
        <Input label={at('purchaseCost')} inputMode="decimal" value={f.purchase_cost} error={fields.purchase_cost} onChange={(e) => set({ purchase_cost: e.target.value })} />
        <Select label={at('currency')} value={f.currency} error={fields.currency} onChange={(e) => set({ currency: e.target.value })} options={[{ value: '', label: '—' }, ...CURRENCIES.map((c) => ({ value: c, label: c }))]} />
        <Input label={at('warrantyEnds')} type="date" value={f.warranty_ends_on} error={fields.warranty_ends_on} onChange={(e) => set({ warranty_ends_on: e.target.value })} />
        <TextArea label={at('notes')} value={f.notes} error={fields.notes} onChange={(v) => set({ notes: v })} />
        <FormError message={error ? formError(error, at('saveFailed')) : null} />
      </div>
    </Modal>
  );
}

function AssetDrawer({ id, categories, onClose, onChanged }: { id: string; categories: AssetCategory[]; onClose: () => void; onChanged: () => void }) {
  const { at } = useAssetsT();
  const source = useAssetsSource();
  const asset = useResource(useCallback((signal: AbortSignal) => source.asset(id, signal), [source, id]));
  const a = asset.data;
  return (
    <Drawer open onClose={onClose} size="lg" title={a ? `${a.asset_tag} · ${a.name}` : at('asset')} description={a?.category?.name}>
      <ResourceView resource={asset} empty="" isEmpty={() => false} errorLabel={at('loadError')}>
        {(data) => <AssetDetail asset={data} categories={categories} onChanged={() => { asset.reload(); onChanged(); }} />}
      </ResourceView>
    </Drawer>
  );
}

type Dialog = 'edit' | 'assign' | 'return' | 'retire' | 'maintenance' | null;

function AssetDetail({ asset: a, categories, onChanged }: { asset: Asset; categories: AssetCategory[]; onChanged: () => void }) {
  const { at, day, money } = useAssetsT();
  const source = useAssetsSource();
  const { toast } = useToast();
  const [dialog, setDialog] = useState<Dialog>(null);
  const [historyKey, setHistoryKey] = useState(0);
  const history = useResource(useCallback((signal: AbortSignal) => source.history(a.id, signal), [source, a.id, historyKey])); // eslint-disable-line react-hooks/exhaustive-deps
  const changed = (message: string) => { setDialog(null); toast({ title: message, tone: 'success' }); setHistoryKey((k) => k + 1); onChanged(); };
  const retired = a.status === 'retired';
  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center gap-2">
        <StatusBadge status={a.status} />
        <Badge size="sm" variant="outline" tone="neutral">{at(`conditions.${a.condition}`)}</Badge>
      </div>
      <dl className="grid gap-4 sm:grid-cols-2">
        <Meta label={at('assignedTo')} value={a.assigned_to ? `${a.assigned_to.name} · ${a.assigned_to.employee_number}` : '—'} />
        <Meta label={at('serialNumber')} value={a.serial_number ?? '—'} />
        <Meta label={at('purchasedOn')} value={day(a.purchased_on)} />
        <Meta label={at('purchaseCost')} value={money(a.purchase_cost, a.currency)} />
        <Meta label={at('warrantyEnds')} value={day(a.warranty_ends_on)} />
        {a.notes && <Meta label={at('notes')} value={a.notes} />}
      </dl>
      <div className="flex flex-wrap gap-2">
        {a.status === 'available' && <Button onClick={() => setDialog('assign')}>{at('assign')}</Button>}
        {a.status === 'assigned' && <Button onClick={() => setDialog('return')}>{at('recordReturn')}</Button>}
        {!retired && <Button variant="secondary" onClick={() => setDialog('edit')}>{at('edit')}</Button>}
        {!retired && a.status !== 'assigned' && <Button variant="secondary" onClick={() => setDialog('maintenance')}>{at('addMaintenance')}</Button>}
        {a.status === 'available' && <Button variant="tertiary" onClick={() => setDialog('retire')}>{at('retire')}</Button>}
        {retired && <Button variant="secondary" onClick={() => setDialog('retire')}>{at('reinstate')}</Button>}
      </div>
      <ResourceView resource={history} empty="" isEmpty={() => false} errorLabel={at('loadError')}>
        {(h) => (
          <>
            <Maintenance records={h.maintenance} onChanged={() => changed(at('saved'))} />
            <section aria-labelledby="asset-history" className="space-y-2">
              <h3 id="asset-history" className="text-sm font-semibold text-fg">{at('assignmentHistory')}</h3>
              {h.assignments.length === 0 ? <p className="text-sm text-fg-muted">{at('noHistory')}</p> : (
                <ol className="space-y-3">
                  {h.assignments.map((x) => (
                    <li key={x.id} className="rounded-md border border-line p-3 text-sm">
                      <p className="font-medium text-fg">{x.employee?.name ?? '—'}</p>
                      <p className="text-fg-secondary">{at('historyLine', { from: day(x.assigned_on), to: x.returned_on ? day(x.returned_on) : at('present') })}</p>
                      <p className="text-fg-muted">{at('conditionLine', { out: at(`conditions.${x.condition_out}`), back: x.condition_in ? at(`conditions.${x.condition_in}`) : '—' })}</p>
                      {x.return_notes && <p className="whitespace-pre-line text-fg-muted">{x.return_notes}</p>}
                    </li>
                  ))}
                </ol>
              )}
            </section>
          </>
        )}
      </ResourceView>
      {dialog === 'edit' && <AssetForm asset={a} categories={categories} onClose={() => setDialog(null)} onSaved={() => changed(at('saved'))} />}
      {dialog === 'assign' && <AssignDialog asset={a} onClose={() => setDialog(null)} onDone={() => changed(at('assigned'))} />}
      {dialog === 'return' && <ReturnDialog asset={a} onClose={() => setDialog(null)} onDone={() => changed(at('returned'))} />}
      {dialog === 'maintenance' && <MaintenanceDialog asset={a} onClose={() => setDialog(null)} onDone={() => changed(at('saved'))} />}
      <ConfirmationDialog open={dialog === 'retire'} tone={retired ? 'primary' : 'danger'} title={retired ? at('reinstateTitle') : at('retireTitle')}
        description={retired ? at('reinstateDescription') : at('retireDescription')} confirmLabel={retired ? at('reinstate') : at('retire')}
        onCancel={() => setDialog(null)}
        onConfirm={async () => {
          try {
            await source.setStatus(a.id, retired ? 'available' : 'retired', a.version);
            changed(at('saved'));
          } catch (e) {
            toast({ title: errorMessage(e, at('saveFailed')), tone: 'danger' });
          }
        }} />
    </div>
  );
}

function AssignDialog({ asset, onClose, onDone }: { asset: Asset; onClose: () => void; onDone: () => void }) {
  const { at, t } = useAssetsT();
  const source = useAssetsSource();
  const employees = useEmployeeSource();
  const [search, setSearch] = useState('');
  const [term, setTerm] = useState('');
  const [employeeId, setEmployeeId] = useState('');
  const [dates, setDates] = useState({ assigned_on: '', due_back_on: '', notes: '' });
  const { error, busy, save } = useSave();
  const fields = validationErrors(error);
  const people = useResource(useCallback((signal: AbortSignal) => employees.list({ ...emptyEmployeeQuery, search: term, status: 'active' }, signal), [employees, term]));
  const submit = () => save(async () => {
    await source.assign(asset.id, { employee_id: employeeId, assigned_on: blank(dates.assigned_on), due_back_on: blank(dates.due_back_on), notes: blank(dates.notes) }, asset.version);
    onDone();
  });
  return (
    <Modal open onClose={onClose} size="md" title={at('assignTitle', { asset: asset.asset_tag })}
      footer={<><Button variant="secondary" onClick={onClose}>{t('ui.cancel')}</Button><Button loading={busy} disabled={!employeeId} onClick={submit}>{at('assign')}</Button></>}>
      <div className="grid gap-4 sm:grid-cols-2">
        <form role="search" className="flex items-end gap-2 sm:col-span-2" onSubmit={(e) => { e.preventDefault(); setTerm(search.trim()); }}>
          <div className="flex-1"><Input label={at('findEmployee')} type="search" value={search} onChange={(e) => setSearch(e.target.value)} /></div>
          <Button type="submit" variant="secondary">{at('searchAction')}</Button>
        </form>
        <div className="sm:col-span-2">
          <Select label={at('employee')} required placeholder={at('chooseEmployee')} value={employeeId} error={fields.employee_id} onChange={(e) => setEmployeeId(e.target.value)}
            options={(people.data?.data ?? []).map((p) => ({ value: p.id, label: `${p.preferred_name || p.first_name} ${p.last_name} · ${p.employee_number}` }))} />
        </div>
        {people.error ? (
          <div role="alert" className="flex flex-wrap items-center gap-2 text-sm text-danger-fg sm:col-span-2">
            {errorMessage(people.error, at('peopleError'))}
            <Button size="sm" variant="secondary" onClick={people.reload}>{t('ui.retry')}</Button>
          </div>
        ) : null}
        <Input label={at('assignedOn')} type="date" value={dates.assigned_on} error={fields.assigned_on} onChange={(e) => setDates({ ...dates, assigned_on: e.target.value })} />
        <Input label={at('dueBack')} type="date" value={dates.due_back_on} error={fields.due_back_on} onChange={(e) => setDates({ ...dates, due_back_on: e.target.value })} />
        <TextArea label={at('notes')} value={dates.notes} error={fields.notes} onChange={(v) => setDates({ ...dates, notes: v })} />
        <FormError message={error ? formError(error, at('saveFailed')) : null} />
      </div>
    </Modal>
  );
}

function ReturnDialog({ asset, onClose, onDone }: { asset: Asset; onClose: () => void; onDone: () => void }) {
  const { at, t } = useAssetsT();
  const source = useAssetsSource();
  const [f, setF] = useState({ condition: asset.condition, returned_on: '', notes: '' });
  const { error, busy, save } = useSave();
  const fields = validationErrors(error);
  const submit = () => save(async () => {
    await source.returnAsset(asset.id, { condition: f.condition, returned_on: blank(f.returned_on), notes: blank(f.notes) }, asset.version);
    onDone();
  });
  return (
    <Modal open onClose={onClose} size="md" title={at('returnTitle', { asset: asset.asset_tag })} description={asset.assigned_to?.name}
      footer={<><Button variant="secondary" onClick={onClose}>{t('ui.cancel')}</Button><Button loading={busy} onClick={submit}>{at('recordReturn')}</Button></>}>
      <div className="grid gap-4 sm:grid-cols-2">
        <Select label={at('returnCondition')} required value={f.condition} error={fields.condition} onChange={(e) => setF({ ...f, condition: e.target.value as AssetCondition })}
          options={CONDITIONS.map((c) => ({ value: c, label: at(`conditions.${c}`) }))} />
        <Input label={at('returnedOnLabel')} type="date" value={f.returned_on} error={fields.returned_on} onChange={(e) => setF({ ...f, returned_on: e.target.value })} />
        <TextArea label={at('notes')} value={f.notes} error={fields.notes} onChange={(v) => setF({ ...f, notes: v })} />
        <FormError message={error ? formError(error, at('saveFailed')) : null} />
      </div>
    </Modal>
  );
}

function Maintenance({ records, onChanged }: { records: MaintenanceRecord[]; onChanged: () => void }) {
  const { at, day, money } = useAssetsT();
  const source = useAssetsSource();
  const { busy, run } = useAction();
  const [cancelling, setCancelling] = useState<MaintenanceRecord | null>(null);
  const move = (m: MaintenanceRecord, status: MaintenanceStatus) => run(`${m.id}:${status}`, () => source.updateMaintenance(m.id, status, m.version)).then((ok) => { if (ok) onChanged(); });
  return (
    <section aria-labelledby="asset-maintenance" className="space-y-2">
      <h3 id="asset-maintenance" className="text-sm font-semibold text-fg">{at('maintenance')}</h3>
      {records.length === 0 ? <p className="text-sm text-fg-muted">{at('noMaintenance')}</p> : (
        <ul className="space-y-3">
          {records.map((m) => (
            <li key={m.id} className="space-y-1 rounded-md border border-line p-3 text-sm">
              <div className="flex flex-wrap items-center gap-2">
                <span className="font-medium text-fg">{at(`kinds.${m.kind}`)}</span>
                <Badge size="sm" dot tone={workTone[m.status]}>{at(`work.${m.status}`)}</Badge>
              </div>
              <p className="whitespace-pre-line text-fg-secondary">{m.description}</p>
              <p className="text-fg-muted">{[m.vendor, money(m.cost, m.currency), m.completed_on ? at('completedOn', { date: day(m.completed_on) }) : m.scheduled_on ? at('scheduledFor', { date: day(m.scheduled_on) }) : null].filter((x) => x && x !== '—').join(' · ')}</p>
              {NEXT[m.status].length > 0 && (
                <div className="flex flex-wrap gap-2 pt-1">
                  {NEXT[m.status].map((s) => (
                    <Button key={s} size="sm" variant={s === 'cancelled' ? 'tertiary' : 'secondary'} loading={busy === `${m.id}:${s}`}
                      onClick={() => (s === 'cancelled' ? setCancelling(m) : void move(m, s))}>
                      {at(`move.${s}`)}
                    </Button>
                  ))}
                </div>
              )}
            </li>
          ))}
        </ul>
      )}
      <ConfirmationDialog open={cancelling !== null} title={at('cancelWorkTitle')} description={at('cancelWorkDescription')} confirmLabel={at('cancelWorkConfirm')}
        onCancel={() => setCancelling(null)}
        onConfirm={async () => { if (cancelling) await move(cancelling, 'cancelled'); setCancelling(null); }} />
    </section>
  );
}

function MaintenanceDialog({ asset, onClose, onDone }: { asset: Asset; onClose: () => void; onDone: () => void }) {
  const { at, t } = useAssetsT();
  const source = useAssetsSource();
  const [f, setF] = useState({ kind: 'repair' as MaintenanceKind, description: '', vendor: '', cost: '', currency: '', status: 'scheduled' as MaintenanceInput['status'], scheduled_on: '' });
  const { error, busy, save } = useSave();
  const fields = validationErrors(error);
  const submit = () => save(async () => {
    await source.addMaintenance(asset.id, { kind: f.kind, description: f.description.trim(), vendor: blank(f.vendor), cost: blank(f.cost), currency: blank(f.currency), status: f.status, scheduled_on: blank(f.scheduled_on) });
    onDone();
  });
  return (
    <Modal open onClose={onClose} size="lg" title={at('addMaintenance')} description={`${asset.asset_tag} · ${asset.name}`}
      footer={<><Button variant="secondary" onClick={onClose}>{t('ui.cancel')}</Button><Button loading={busy} disabled={!f.description.trim()} onClick={submit}>{at('save')}</Button></>}>
      <div className="grid gap-4 sm:grid-cols-2">
        <Select label={at('kind')} value={f.kind} onChange={(e) => setF({ ...f, kind: e.target.value as MaintenanceKind })} options={KINDS.map((k) => ({ value: k, label: at(`kinds.${k}`) }))} />
        <Select label={at('statusLabel')} value={f.status} onChange={(e) => setF({ ...f, status: e.target.value as MaintenanceInput['status'] })}
          options={[{ value: 'scheduled', label: at('work.scheduled') }, { value: 'in_progress', label: at('work.in_progress') }]} hint={at('inProgressHint')} />
        <TextArea label={at('description')} required value={f.description} error={fields.description} onChange={(v) => setF({ ...f, description: v })} />
        <Input label={at('vendor')} maxLength={120} value={f.vendor} onChange={(e) => setF({ ...f, vendor: e.target.value })} />
        <Input label={at('scheduledOn')} type="date" value={f.scheduled_on} onChange={(e) => setF({ ...f, scheduled_on: e.target.value })} />
        <Input label={at('cost')} inputMode="decimal" value={f.cost} error={fields.cost} onChange={(e) => setF({ ...f, cost: e.target.value })} />
        <Select label={at('currency')} value={f.currency} error={fields.currency} onChange={(e) => setF({ ...f, currency: e.target.value })} options={[{ value: '', label: '—' }, ...CURRENCIES.map((c) => ({ value: c, label: c }))]} />
        <FormError message={error ? formError(error, at('saveFailed')) : null} />
      </div>
    </Modal>
  );
}

function Categories() {
  const { at } = useAssetsT();
  const { toast } = useToast();
  const source = useAssetsSource();
  const categories = useResource(useCallback((signal: AbortSignal) => source.categories(signal), [source]));
  const [editing, setEditing] = useState<AssetCategory | 'new' | null>(null);
  const columns: DataTableColumn<AssetCategory>[] = [
    { id: 'name', header: at('name'), primary: true, cell: (c) => (
      <button type="button" className="text-start font-medium text-fg-link hover:underline" onClick={() => setEditing(c)}>{c.name}</button>
    ) },
    { id: 'count', header: at('assetsCount'), cell: (c) => <span className="tabular-nums">{c.assets_count}</span> },
    { id: 'active', header: at('statusLabel'), cell: (c) => <Badge size="sm" tone={c.active ? 'success' : 'neutral'}>{at(c.active ? 'active' : 'inactive')}</Badge> },
  ];
  return (
    <Card title={at('categories')} headingLevel={2} padding="none" description={at('categoriesHint')} actions={<Button onClick={() => setEditing('new')}>{at('newCategory')}</Button>}>
      {categories.error instanceof ApiNotConnectedError ? <div className="p-4"><NotConnected /></div> : (
        <DataTable caption={at('categories')} hideCaption columns={columns} rows={categories.data ?? []} getRowId={(c) => c.id}
          loading={categories.loading && categories.data === undefined} error={categories.error ? errorMessage(categories.error, at('loadError')) : null} onRetry={categories.reload}
          empty={<EmptyState title={at('noCategories')} />} />
      )}
      {editing && <CategoryForm category={editing === 'new' ? undefined : editing} onClose={() => setEditing(null)} onSaved={() => { setEditing(null); toast({ title: at('saved'), tone: 'success' }); categories.reload(); }} />}
    </Card>
  );
}

function CategoryForm({ category, onClose, onSaved }: { category?: AssetCategory; onClose: () => void; onSaved: () => void }) {
  const { at, t } = useAssetsT();
  const source = useAssetsSource();
  const [f, setF] = useState({ name: category?.name ?? '', description: category?.description ?? '', active: category?.active ?? true });
  const { error, busy, save } = useSave();
  const fields = validationErrors(error);
  const submit = () => save(async () => {
    await source.saveCategory({ name: f.name.trim(), description: blank(f.description), active: f.active }, category?.id, category?.version);
    onSaved();
  });
  return (
    <Modal open onClose={onClose} size="md" title={category ? at('editCategory') : at('newCategory')}
      footer={<><Button variant="secondary" onClick={onClose}>{t('ui.cancel')}</Button><Button loading={busy} disabled={!f.name.trim()} onClick={submit}>{at('save')}</Button></>}>
      <div className="grid gap-4 sm:grid-cols-2">
        <div className="sm:col-span-2"><Input label={at('name')} required maxLength={80} value={f.name} error={fields.name} onChange={(e) => setF({ ...f, name: e.target.value })} /></div>
        <TextArea label={at('description')} value={f.description} error={fields.description} onChange={(v) => setF({ ...f, description: v })} />
        <Checkbox label={at('activeHint')} checked={f.active} onChange={(e) => setF({ ...f, active: e.target.checked })} />
        <FormError message={error ? formError(error, at('saveFailed')) : null} />
      </div>
    </Modal>
  );
}
