import { useCallback, useState, type FormEvent } from 'react';
import { Link } from 'react-router';
import type { MessageKey } from '@wingora/localization';
import { ApiError } from '@/api/http';
import { useEmployeeSource, type DirectoryOptions, type EmployeeDetail, type HrRecord, type PersonalDetails, type SensitiveDetails } from '@/api/employees';
import { Avatar, Badge, Button, Card, ChevronLeftIcon, DatePicker, EmptyState, IconButton, Input, Modal, Select, Tabs, XIcon, useToast } from '@/components/ui';
import { useSession } from '@/auth/session';
import { useI18n } from '@/i18n/i18n';
import { PageHeader } from '@/shell/page-header';
import { EmployeeForm } from './employee-form';
import { DefinitionList, displayName, errorMessage, ResourceView, StatusBadge, useResource, validationErrors } from './shared';

const noOptions: DirectoryOptions = { departments: [], designations: [], legalEntities: [], workLocations: [] };

/** Each tab is requested only when the server's `abilities` allow it; the API still enforces every rule. */
export function EmployeeProfile({ id }: { id: string }) {
  const { t } = useI18n();
  const source = useEmployeeSource();
  const [editing, setEditing] = useState(false);
  const employee = useResource(useCallback((signal: AbortSignal) => source.get(id, signal), [source, id]));
  const options = useResource(useCallback((signal: AbortSignal) => (editing ? source.options(signal) : Promise.resolve(noOptions)), [source, editing]));

  if (employee.error instanceof ApiError && [403, 404].includes(employee.error.status)) {
    return <Card padding="none"><EmptyState headingLevel={2} title={t('employees.notFound')} action={<Link className="text-fg-link" to="/employees">{t('employees.back')}</Link>} /></Card>;
  }

  return (
    <ResourceView resource={employee} empty="">
      {(e) => {
        const tabs = [
          { id: 'overview', label: t('employees.tab.overview'), content: <Employment e={e} /> },
          e.abilities.view_personal && { id: 'personal', label: t('employees.tab.personal'), content: <Personal e={e} /> },
          { id: 'history', label: t('employees.tab.history'), content: <History id={e.id} /> },
          { id: 'documents', label: t('employees.tab.documents'), content: <Documents id={e.id} /> },
          e.abilities.view_personal && { id: 'contacts', label: t('employees.tab.contacts'), content: <Contacts e={e} /> },
          { id: 'policies', label: t('employees.tab.policies'), content: <Policies id={e.id} /> },
          e.abilities.view_records && { id: 'records', label: t('employees.tab.records'), content: <Records e={e} /> },
        ].filter((tab) => tab !== false);
        return (
          <>
            <Link to="/employees" className="mb-3 inline-flex items-center gap-1 text-sm text-fg-link"><ChevronLeftIcon size={16} />{t('employees.back')}</Link>
            <PageHeader eyebrow={e.employee_number} title={<span className="flex items-center gap-3"><Avatar name={displayName(e)} size="md" />{displayName(e)}</span>}
              description={<span className="flex flex-wrap items-center gap-2">{e.job_title ?? e.designation?.name}<StatusBadge status={e.status} /></span>}
              actions={e.abilities.update && <Button onClick={() => setEditing(true)}>{t('employees.edit')}</Button>} />
            <Card padding="none"><Tabs label={t('employees.profile')} tabs={tabs} defaultValue="overview" /></Card>
            {editing && options.data && <EmployeeForm open employee={e} options={options.data} onClose={() => setEditing(false)} onSaved={() => { setEditing(false); employee.reload(); }} />}
          </>
        );
      }}
    </ResourceView>
  );
}

function Employment({ e }: { e: EmployeeDetail }) {
  const { t } = useI18n();
  return (
    <div className="p-(--card-padding)">
      <DefinitionList items={[
        [t('employees.number'), e.employee_number], [t('employees.workEmail'), e.work_email], [t('employees.jobTitle'), e.job_title],
        [t('employees.designation'), e.designation?.name], [t('employees.department'), e.department?.name],
        [t('employees.manager'), e.manager && <Link className="text-fg-link" to={`/employees/${e.manager.id}`}>{e.manager.name}</Link>],
        [t('employees.legalEntity'), e.legal_entity && `${e.legal_entity.name} (${e.legal_entity.country_code})`],
        [t('employees.workLocation'), e.work_location && `${e.work_location.name} · ${e.work_location.subdivision_code}`],
        [t('employees.employmentType'), e.employment_type], [t('employees.hireDate'), e.hire_date], [t('employees.terminationDate'), e.termination_date],
      ]} />
    </div>
  );
}

function Personal({ e }: { e: EmployeeDetail }) {
  const { t } = useI18n();
  const source = useEmployeeSource();
  const { toast } = useToast();
  const personal = useResource(useCallback((signal: AbortSignal) => source.personal(e.id, signal), [source, e.id]));
  const [form, setForm] = useState<PersonalDetails | null>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [sensitive, setSensitive] = useState<SensitiveDetails | null>(null);

  async function save(event: FormEvent) {
    event.preventDefault();
    if (!form) return;
    try {
      await source.updatePersonal(e.id, { personal_email: form.personal_email || null, mobile_phone: form.mobile_phone || null, nationality: form.nationality || null });
      toast({ title: t('employees.saved'), tone: 'success' });
      setForm(null);
      personal.reload();
    } catch (error) {
      setErrors(validationErrors(error));
    }
  }

  return (
    <div className="space-y-6 p-(--card-padding)">
      <ResourceView resource={personal} empty="">
        {(p) => (
          <>
            <DefinitionList items={[
              [t('employees.preferredName'), p.preferred_name], [t('employees.personalEmail'), p.personal_email], [t('employees.mobilePhone'), p.mobile_phone],
              [t('employees.nationality'), p.nationality],
              [t('employees.address'), p.home_address && [p.home_address.line1, p.home_address.line2, p.home_address.city, p.home_address.region, p.home_address.postal_code, p.home_address.country_code].filter(Boolean).join(', ')],
            ]} />
            {e.abilities.update_personal && <Button variant="secondary" onClick={() => setForm(p)}>{t('employees.edit')}</Button>}
          </>
        )}
      </ResourceView>
      {e.abilities.view_sensitive && (
        <section aria-labelledby="sensitive-heading" className="border-t border-line pt-4">
          <h3 id="sensitive-heading" className="mb-1 text-sm font-semibold text-fg">{t('employees.sensitiveTitle')}</h3>
          <p className="mb-3 text-xs text-fg-muted">{t('employees.sensitiveHint')}</p>
          {sensitive
            ? <DefinitionList items={[[t('employees.nationalId'), sensitive.national_id], [t('employees.bankAccount'), sensitive.bank_account_number], [t('employees.dateOfBirth'), sensitive.date_of_birth]]} />
            : <Button variant="secondary" onClick={() => source.sensitive(e.id).then(setSensitive, (err: unknown) => toast({ title: errorMessage(err, t('employees.loadError')), tone: 'danger' }))}>{t('employees.sensitiveShow')}</Button>}
        </section>
      )}
      {form && (
        <Modal open onClose={() => setForm(null)} title={t('employees.tab.personal')}
          footer={<><Button variant="secondary" onClick={() => setForm(null)}>{t('ui.cancel')}</Button><Button type="submit" form="personal-form">{t('employees.save')}</Button></>}>
          <form id="personal-form" onSubmit={save} noValidate className="grid gap-4">
            <Input label={t('employees.personalEmail')} type="email" value={form.personal_email ?? ''} error={errors.personal_email} onChange={(ev) => setForm({ ...form, personal_email: ev.target.value })} />
            <Input label={t('employees.mobilePhone')} type="tel" value={form.mobile_phone ?? ''} error={errors.mobile_phone} onChange={(ev) => setForm({ ...form, mobile_phone: ev.target.value })} />
            <Input label={t('employees.nationality')} maxLength={2} value={form.nationality ?? ''} error={errors.nationality} onChange={(ev) => setForm({ ...form, nationality: ev.target.value.toUpperCase() })} />
          </form>
        </Modal>
      )}
    </div>
  );
}

function History({ id }: { id: string }) {
  const { t } = useI18n();
  const source = useEmployeeSource();
  const history = useResource(useCallback((signal: AbortSignal) => source.history(id, signal), [source, id]));
  return (
    <div className="p-(--card-padding)">
      <ResourceView resource={history} empty={t('employees.historyEmpty')} isEmpty={(h) => h.data.length === 0}>
        {(h) => (
          <ol className="space-y-4">
            {h.data.map((entry) => (
              <li key={entry.id} className="border-s-2 border-line ps-4">
                <div className="flex flex-wrap items-center gap-2 text-sm">
                  <span className="font-medium text-fg">{t(`employees.change.${entry.change_type}` as MessageKey)}</span>
                  <time className="text-fg-muted" dateTime={entry.effective_date}>{entry.effective_date}</time>
                </div>
                <ul className="mt-1 text-sm text-fg-secondary">
                  {Object.entries(entry.changes).map(([field, c]) => <li key={field}>{t('employees.changeLine', { field, from: c.from ?? '—', to: c.to ?? '—' })}</li>)}
                </ul>
                {entry.note && <p className="mt-1 text-sm text-fg">{entry.note}</p>}
                {entry.recorded_by && <p className="mt-1 text-xs text-fg-muted">{t('employees.recordedBy', { name: entry.recorded_by })}</p>}
              </li>
            ))}
          </ol>
        )}
      </ResourceView>
    </div>
  );
}

function Documents({ id }: { id: string }) {
  const { t } = useI18n();
  const source = useEmployeeSource();
  const { toast } = useToast();
  const docs = useResource(useCallback((signal: AbortSignal) => source.documents(id, signal), [source, id]));
  const [file, setFile] = useState<File | null>(null);
  const [category, setCategory] = useState('general');
  const categories = docs.data?.meta.upload_categories ?? [];

  async function upload(event: FormEvent) {
    event.preventDefault();
    if (!file) return;
    try {
      await source.upload(id, file, category);
      toast({ title: t('employees.uploaded'), tone: 'success' });
      setFile(null);
      docs.reload();
    } catch (error) {
      toast({ title: validationErrors(error).file ?? errorMessage(error, t('employees.loadError')), tone: 'danger' });
    }
  }

  return (
    <div className="space-y-6 p-(--card-padding)">
      <ResourceView resource={docs} empty={t('employees.documentsEmpty')} isEmpty={(d) => d.data.length === 0}>
        {(d) => (
          <ul className="divide-y divide-line">
            {d.data.map((doc) => (
              <li key={doc.id} className="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                <span className="min-w-0 truncate">{doc.name} <Badge size="sm">{doc.category}</Badge></span>
                <Button variant="link" onClick={() => source.download(doc.id, doc.name)} aria-label={t('employees.download', { name: doc.name })}>{t('employees.tab.documents')}</Button>
              </li>
            ))}
          </ul>
        )}
      </ResourceView>
      {categories.length > 0 && (
        <form onSubmit={upload} className="flex flex-wrap items-end gap-3 border-t border-line pt-4">
          <label className="text-sm font-medium text-fg">{t('employees.file')}
            <input type="file" className="mt-1 block text-sm" accept=".pdf,.png,.jpg,.jpeg,.docx,.xlsx,.csv,.txt" onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
          </label>
          <Select label={t('employees.category')} value={category} onChange={(e) => setCategory(e.target.value)} options={categories.map((c) => ({ value: c, label: c }))} />
          <Button type="submit" disabled={!file}>{t('employees.upload')}</Button>
        </form>
      )}
    </div>
  );
}

function Contacts({ e }: { e: EmployeeDetail }) {
  const { t } = useI18n();
  const source = useEmployeeSource();
  const contacts = useResource(useCallback((signal: AbortSignal) => source.contacts(e.id, signal), [source, e.id]));
  const [form, setForm] = useState<{ name: string; relationship: string; phone: string; email: string; is_primary: boolean } | null>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const relationships = ['spouse', 'partner', 'parent', 'child', 'sibling', 'relative', 'friend', 'other'];

  async function save(event: FormEvent) {
    event.preventDefault();
    if (!form) return;
    try {
      await source.addContact(e.id, { ...form, email: form.email || null });
      setForm(null);
      contacts.reload();
    } catch (error) {
      setErrors(validationErrors(error));
    }
  }

  return (
    <div className="space-y-4 p-(--card-padding)">
      <ResourceView resource={contacts} empty={t('employees.contactsEmpty')} isEmpty={(c) => c.length === 0}>
        {(list) => (
          <ul className="grid gap-3 sm:grid-cols-2">
            {list.map((c) => (
              <li key={c.id} className="flex items-start justify-between gap-2 rounded-md border border-line p-3 text-sm">
                <div className="min-w-0">
                  <div className="font-medium text-fg">{c.name} {c.is_primary && <Badge tone="accent" size="sm">{t('employees.primary')}</Badge>}</div>
                  <div className="text-fg-muted">{c.relationship} · {c.phone}{c.email ? ` · ${c.email}` : ''}</div>
                </div>
                {e.abilities.update_personal && <IconButton variant="tertiary" size="sm" icon={<XIcon size={16} />} label={t('employees.remove', { name: c.name })}
                  onClick={() => source.removeContact(e.id, c.id).then(contacts.reload)} />}
              </li>
            ))}
          </ul>
        )}
      </ResourceView>
      {e.abilities.update_personal && <Button variant="secondary" onClick={() => setForm({ name: '', relationship: 'spouse', phone: '', email: '', is_primary: false })}>{t('employees.addContact')}</Button>}
      {form && (
        <Modal open onClose={() => setForm(null)} title={t('employees.addContact')}
          footer={<><Button variant="secondary" onClick={() => setForm(null)}>{t('ui.cancel')}</Button><Button type="submit" form="contact-form">{t('employees.save')}</Button></>}>
          <form id="contact-form" onSubmit={save} noValidate className="grid gap-4">
            <Input label={t('employees.name')} required value={form.name} error={errors.name} onChange={(ev) => setForm({ ...form, name: ev.target.value })} />
            <Select label={t('employees.relationship')} value={form.relationship} error={errors.relationship} onChange={(ev) => setForm({ ...form, relationship: ev.target.value })} options={relationships.map((r) => ({ value: r, label: r }))} />
            <Input label={t('employees.phone')} type="tel" required value={form.phone} error={errors.phone} onChange={(ev) => setForm({ ...form, phone: ev.target.value })} />
            <Input label={t('employees.email')} type="email" value={form.email} error={errors.email} onChange={(ev) => setForm({ ...form, email: ev.target.value })} />
            <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={form.is_primary} onChange={(ev) => setForm({ ...form, is_primary: ev.target.checked })} />{t('employees.primary')}</label>
          </form>
        </Modal>
      )}
    </div>
  );
}

function Policies({ id }: { id: string }) {
  const { t } = useI18n();
  const { me } = useSession();
  const source = useEmployeeSource();
  const policies = useResource(useCallback((signal: AbortSignal) => source.policies(id, signal), [source, id]));
  const self = useResource(useCallback((signal: AbortSignal) => source.list({ search: '', status: '', department: '', designation: '', country: '', legal_entity: '', employment_type: '', sort: 'name', page: 1 }, signal)
    .then((page) => page.data.length === 1 && page.data[0]!.id === id && !me?.permissions.some((p) => p === 'employees.view.all' || p === 'employees.view.department')), [source, id, me]));
  return (
    <div className="p-(--card-padding)">
      <ResourceView resource={policies} empty={t('employees.policiesEmpty')} isEmpty={(p) => p.length === 0}>
        {(list) => (
          <ul className="divide-y divide-line">
            {list.map((p) => (
              <li key={p.id} className="flex flex-wrap items-center justify-between gap-2 py-3 text-sm">
                <div><div className="font-medium text-fg">{p.title} <span className="text-fg-muted">v{p.version}</span></div>{p.summary && <div className="text-fg-muted">{p.summary}</div>}</div>
                {p.acknowledged_at
                  ? <Badge tone="success" size="sm">{t('employees.acknowledgedOn', { date: p.acknowledged_at.slice(0, 10) })}</Badge>
                  : self.data ? <Button size="sm" onClick={() => source.acknowledge(p.id).then(policies.reload)}>{t('employees.acknowledge')}</Button> : <Badge tone="warning" size="sm">{t('employees.pending')}</Badge>}
              </li>
            ))}
          </ul>
        )}
      </ResourceView>
    </div>
  );
}

const SEVERITIES = ['verbal_warning', 'written_warning', 'final_warning', 'suspension', 'other'];
function Records({ e }: { e: EmployeeDetail }) {
  const { t } = useI18n();
  const source = useEmployeeSource();
  const records = useResource(useCallback((signal: AbortSignal) => source.records(e.id, signal), [source, e.id]));
  const blank = { type: 'note' as HrRecord['type'], severity: '', title: '', body: '', occurred_on: '' };
  const [form, setForm] = useState<typeof blank | null>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});

  async function save(event: FormEvent) {
    event.preventDefault();
    if (!form) return;
    try {
      await source.addRecord(e.id, { ...form, severity: form.type === 'disciplinary' ? form.severity || null : null });
      setForm(null);
      records.reload();
    } catch (error) {
      setErrors(validationErrors(error));
    }
  }

  return (
    <div className="space-y-4 p-(--card-padding)">
      <p className="text-xs text-fg-muted">{t('employees.recordsHint')}</p>
      <ResourceView resource={records} empty={t('employees.recordsEmpty')} isEmpty={(r) => r.length === 0}>
        {(list) => (
          <ul className="space-y-3">
            {list.map((r) => (
              <li key={r.id} className="rounded-md border border-line p-3 text-sm">
                <div className="flex flex-wrap items-center gap-2">
                  <Badge tone={r.type === 'disciplinary' ? 'danger' : 'neutral'} size="sm">{t(`employees.record.${r.type}`)}</Badge>
                  <span className="font-medium text-fg">{r.title}</span>
                  <time className="text-fg-muted" dateTime={r.occurred_on}>{r.occurred_on}</time>
                  {r.severity && <span className="text-fg-muted">{r.severity.replace('_', ' ')}</span>}
                </div>
                <p className="mt-1 whitespace-pre-line text-fg-secondary">{r.body}</p>
                {r.author && <p className="mt-1 text-xs text-fg-muted">{t('employees.recordedBy', { name: r.author })}</p>}
              </li>
            ))}
          </ul>
        )}
      </ResourceView>
      {e.abilities.manage_records && <Button variant="secondary" onClick={() => setForm(blank)}>{t('employees.addRecord')}</Button>}
      {form && (
        <Modal open onClose={() => setForm(null)} title={t('employees.addRecord')}
          footer={<><Button variant="secondary" onClick={() => setForm(null)}>{t('ui.cancel')}</Button><Button type="submit" form="record-form">{t('employees.save')}</Button></>}>
          <form id="record-form" onSubmit={save} noValidate className="grid gap-4">
            <Select label={t('employees.recordType')} value={form.type} onChange={(ev) => setForm({ ...form, type: ev.target.value as HrRecord['type'] })}
              options={(['note', 'disciplinary'] as const).map((v) => ({ value: v, label: t(`employees.record.${v}`) }))} />
            {form.type === 'disciplinary' && <Select label={t('employees.severity')} required placeholder={t('employees.none')} value={form.severity} error={errors.severity}
              onChange={(ev) => setForm({ ...form, severity: ev.target.value })} options={SEVERITIES.map((s) => ({ value: s, label: s.replace('_', ' ') }))} />}
            <Input label={t('employees.recordTitle')} required value={form.title} error={errors.title} onChange={(ev) => setForm({ ...form, title: ev.target.value })} />
            <Input label={t('employees.recordBody')} required value={form.body} error={errors.body} onChange={(ev) => setForm({ ...form, body: ev.target.value })} />
            <DatePicker label={t('employees.occurredOn')} required value={form.occurred_on} error={errors.occurred_on} onChange={(v) => setForm({ ...form, occurred_on: v })} />
          </form>
        </Modal>
      )}
    </div>
  );
}
