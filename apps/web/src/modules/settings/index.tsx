import { useCallback, useState } from 'react';
import { Permission as P } from '@wingora/permissions';
import type { MessageKey } from '@wingora/localization';
import { ApiNotConnectedError } from '@/api/dashboard';
import {
  useSettingsSource, type ApiToken, type LegalEntity, type LegalEntityInput, type TenantUser, type WorkLocation, type WorkLocationInput,
} from '@/api/settings';
import { Badge, Button, Card, Checkbox, ConfirmationDialog, DataTable, EmptyState, Input, Modal, Select, Tabs, useToast, type DataTableColumn } from '@/components/ui';
import { PageHeader } from '@/shell/page-header';
import { useSession } from '@/auth/session';
import { useI18n } from '@/i18n/i18n';
import { errorMessage, FormError, formError, ResourceView, useResource, validationErrors } from '@/modules/employees/shared';
import { Pager } from '@/modules/attendance';
import { useAction, useCan, useSave } from '@/modules/recruitment/shared';

/** Tenant roles an administrator may assign; the server still refuses roles above the actor's own permissions. */
export const TENANT_ROLES = ['organization-owner', 'hr-administrator', 'hr-manager', 'department-manager', 'payroll-administrator', 'auditor', 'employee'] as const;

export function useSettingsT() {
  const { t, locale } = useI18n();
  const st = (key: string, params?: Record<string, string | number>) => t(`settings.${key}` as MessageKey, params);
  const role = (key: string) => { const k = `settings.role.${key}` as MessageKey; const l = t(k); return l === k ? key : l; };
  const when = (iso: string | null) => (iso ? new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(iso)) : '—');
  return { st, t, role, when };
}

function NotConnected() {
  const { st } = useSettingsT();
  return <EmptyState title={st('notConnectedTitle')} description={st('notConnectedDescription')} />;
}

export default function Settings() {
  const { st, t } = useSettingsT();
  const can = useCan();
  const tabs = [
    can(P.OrganizationView, P.OrganizationManage, P.OrganizationSecurityManage) && { id: 'organization', label: st('tab.organization'), content: <OrganizationTab /> },
    can(P.UsersView, P.UsersManage, P.RolesAssign) && { id: 'users', label: st('tab.users'), content: <UsersTab /> },
    can(P.OrganizationView, P.LegalEntitiesManage) && { id: 'entities', label: st('tab.entities'), content: <EntitiesTab /> },
    { id: 'account', label: st('tab.account'), content: <AccountTab /> },
  ].filter((x): x is { id: string; label: string; content: React.ReactElement } => Boolean(x));
  return (
    <>
      <PageHeader title={t('nav.module.settings')} description={st('description')} />
      <Tabs label={t('nav.module.settings')} tabs={tabs} />
    </>
  );
}

/* ---------------- Organization ---------------- */

function OrganizationTab() {
  const { st } = useSettingsT();
  const source = useSettingsSource();
  const can = useCan();
  const { toast } = useToast();
  const { refresh } = useSession();
  const org = useResource(useCallback((signal: AbortSignal) => source.organization(signal), [source]));
  const [name, setName] = useState<string | null>(null);
  const { error, busy, save } = useSave();
  const security = useAction();
  if (org.error instanceof ApiNotConnectedError) return <NotConnected />;
  return (
    <ResourceView resource={org} empty="" isEmpty={() => false} errorLabel={st('loadError')}>
      {(o) => (
        <div className="space-y-6">
          <Card title={st('profile')} headingLevel={2}>
            <form className="grid items-end gap-4 sm:grid-cols-[minmax(0,1fr)_auto]" onSubmit={(e) => {
              e.preventDefault();
              void save(async () => {
                await source.updateOrganization((name ?? o.name).trim());
                toast({ title: st('saved'), tone: 'success' });
                setName(null);
                org.reload();
                await refresh().catch(() => undefined);
              });
            }}>
              <Input label={st('organizationName')} required maxLength={160} value={name ?? o.name} disabled={!can(P.OrganizationManage)}
                error={validationErrors(error).name} onChange={(e) => setName(e.target.value)} />
              {can(P.OrganizationManage) && <Button type="submit" loading={busy} disabled={(name ?? o.name).trim().length < 2}>{st('save')}</Button>}
              <FormError message={error ? formError(error, st('saveFailed')) : null} />
            </form>
            <dl className="mt-4 grid gap-2 text-sm sm:grid-cols-2">
              <div><dt className="text-fg-muted">{st('country')}</dt><dd className="text-fg">{o.country_code}</dd></div>
              <div><dt className="text-fg-muted">{st('status')}</dt><dd className="text-fg">{o.status}</dd></div>
            </dl>
          </Card>
          <Card title={st('security')} headingLevel={2} description={st('securityHint')}>
            <Checkbox label={st('mfaRequired')} description={st('mfaRequiredHint')} checked={o.mfa_required} disabled={!can(P.OrganizationSecurityManage) || security.busy !== null}
              onChange={(e) => { const on = e.target.checked; void security.run('mfa', () => source.updateSecurity(on), st('saved')).then(() => org.reload()); }} />
          </Card>
        </div>
      )}
    </ResourceView>
  );
}

/* ---------------- Users ---------------- */

function UsersTab() {
  const { st, role, when } = useSettingsT();
  const source = useSettingsSource();
  const can = useCan();
  const { me } = useSession();
  const [page, setPage] = useState(1);
  const users = useResource(useCallback((signal: AbortSignal) => source.users(page, signal), [source, page]));
  const [editing, setEditing] = useState<TenantUser | 'new' | null>(null);
  const status = useAction();
  const [disabling, setDisabling] = useState<TenantUser | null>(null);
  const self = (u: TenantUser) => u.id === me?.user.id;
  const setStatus = (u: TenantUser, next: 'active' | 'disabled') => status.run(u.id, () => source.updateStatus(u.id, next), st('saved')).then(users.reload);
  const columns: DataTableColumn<TenantUser>[] = [
    { id: 'name', header: st('name'), primary: true, cell: (u) => <div><span className="font-medium text-fg">{u.name}</span><span className="block text-xs text-fg-muted">{u.email}</span></div> },
    { id: 'roles', header: st('roles'), cell: (u) => u.roles.map(role).join(', ') || '—' },
    { id: 'mfa', header: st('mfa'), hideBelow: 'md', cell: (u) => <Badge size="sm" tone={u.mfa_enabled ? 'success' : 'neutral'}>{st(u.mfa_enabled ? 'on' : 'off')}</Badge> },
    { id: 'last', header: st('lastSignIn'), hideBelow: 'lg', cell: (u) => when(u.last_login_at) },
    { id: 'status', header: st('status'), cell: (u) => <Badge size="sm" dot tone={u.status === 'active' ? 'success' : 'neutral'}>{st(u.status === 'active' ? 'active' : 'disabled')}</Badge> },
    {
      id: 'actions', header: <span className="sr-only">{st('actions')}</span>, align: 'end', cell: (u) => self(u) ? <span className="text-xs text-fg-muted">{st('you')}</span> : (
        <div className="flex justify-end gap-2">
          {can(P.RolesAssign) && <Button size="sm" variant="tertiary" aria-label={st('editRolesFor', { name: u.name })} onClick={() => setEditing(u)}>{st('editRoles')}</Button>}
          {can(P.UsersManage) && (
            <Button size="sm" variant="tertiary" loading={status.busy === u.id} aria-label={st(u.status === 'active' ? 'disableNamed' : 'enableNamed', { name: u.name })}
              onClick={() => (u.status === 'active' ? setDisabling(u) : void setStatus(u, 'active'))}>
              {st(u.status === 'active' ? 'disable' : 'enable')}
            </Button>
          )}
        </div>
      ),
    },
  ];
  return (
    <Card title={st('tab.users')} headingLevel={2} padding="none" description={st('usersHint')}
      actions={can(P.UsersManage) && <Button onClick={() => setEditing('new')}>{st('invite')}</Button>}>
      {users.error instanceof ApiNotConnectedError ? <div className="p-4"><NotConnected /></div> : (
        <>
          <DataTable caption={st('tab.users')} hideCaption columns={columns} rows={users.data?.data ?? []} getRowId={(u) => u.id}
            loading={users.loading && users.data === undefined} error={users.error ? errorMessage(users.error, st('loadError')) : null} onRetry={users.reload}
            empty={<EmptyState title={st('noUsers')} />} />
          <Pager meta={users.data?.meta} onPage={setPage} />
        </>
      )}
      {editing && <UserDialog user={editing === 'new' ? null : editing} onClose={() => setEditing(null)} onSaved={() => { setEditing(null); users.reload(); }} />}
      <ConfirmationDialog open={disabling !== null} title={st('disableTitle', { name: disabling?.name ?? '' })} description={st('disableConfirm')} confirmLabel={st('disable')}
        onCancel={() => setDisabling(null)} onConfirm={async () => { if (disabling) await setStatus(disabling, 'disabled'); setDisabling(null); }} />
    </Card>
  );
}

function UserDialog({ user, onClose, onSaved }: { user: TenantUser | null; onClose: () => void; onSaved: () => void }) {
  const { st, t, role } = useSettingsT();
  const source = useSettingsSource();
  const { toast } = useToast();
  const [input, setInput] = useState({ name: '', email: '', roles: user?.roles ?? ['employee'] });
  const { error, busy, save } = useSave();
  const fields = validationErrors(error);
  const toggle = (key: string, on: boolean) => setInput({ ...input, roles: on ? [...input.roles, key] : input.roles.filter((r) => r !== key) });
  const valid = input.roles.length > 0 && (user !== null || (input.name.trim().length > 1 && input.email.includes('@')));
  return (
    <Modal open onClose={onClose} size="lg" title={user ? st('editRolesFor', { name: user.name }) : st('invite')}
      footer={<><Button variant="secondary" onClick={onClose}>{t('ui.cancel')}</Button>
        <Button loading={busy} disabled={!valid} onClick={() => save(async () => {
          if (user) await source.updateRoles(user.id, input.roles);
          else await source.inviteUser({ name: input.name.trim(), email: input.email.trim(), roles: input.roles });
          toast({ title: st(user ? 'saved' : 'invited'), tone: 'success' });
          onSaved();
        })}>{st(user ? 'save' : 'sendInvite')}</Button></>}>
      <div className="grid gap-4 sm:grid-cols-2">
        {!user && <>
          <Input label={st('name')} required maxLength={160} value={input.name} error={fields.name} onChange={(e) => setInput({ ...input, name: e.target.value })} />
          <Input label={st('email')} type="email" required maxLength={255} value={input.email} error={fields.email} onChange={(e) => setInput({ ...input, email: e.target.value })} />
          <p className="text-xs text-fg-muted sm:col-span-2">{st('inviteHint')}</p>
        </>}
        <fieldset className="sm:col-span-2">
          <legend className="text-sm font-medium text-fg">{st('roles')}</legend>
          <p className="text-xs text-fg-muted">{st('rolesHint')}</p>
          <div className="mt-2 grid gap-2 sm:grid-cols-2">
            {TENANT_ROLES.map((key) => <Checkbox key={key} label={role(key)} checked={input.roles.includes(key)} onChange={(e) => toggle(key, e.target.checked)} />)}
          </div>
          {(fields.roles ?? Object.entries(fields).find(([k]) => k.startsWith('roles.'))?.[1]) && <p role="alert" className="mt-2 text-sm text-danger-fg">{fields.roles ?? st('rolesInvalid')}</p>}
        </fieldset>
        <FormError message={error ? formError(error, st('saveFailed')) : null} />
      </div>
    </Modal>
  );
}

/* ---------------- Legal entities and work locations ---------------- */

function EntitiesTab() {
  const { st } = useSettingsT();
  const source = useSettingsSource();
  const can = useCan();
  const manage = can(P.LegalEntitiesManage);
  const entities = useResource(useCallback((signal: AbortSignal) => source.legalEntities(signal), [source]));
  const locations = useResource(useCallback((signal: AbortSignal) => source.workLocations(signal), [source]));
  const [entity, setEntity] = useState<LegalEntity | 'new' | null>(null);
  const [location, setLocation] = useState<WorkLocation | 'new' | null>(null);
  const activeBadge = (s: string) => <Badge size="sm" dot tone={s === 'active' ? 'success' : 'neutral'}>{st(s === 'active' ? 'active' : 'inactive')}</Badge>;
  const edit = (label: string, onClick: () => void) => manage && <Button size="sm" variant="tertiary" aria-label={st('editNamed', { name: label })} onClick={onClick}>{st('edit')}</Button>;
  const entityColumns: DataTableColumn<LegalEntity>[] = [
    { id: 'name', header: st('name'), primary: true, cell: (e) => <div><span className="font-medium text-fg">{e.name}</span><span className="block text-xs text-fg-muted">{e.legal_name}</span></div> },
    { id: 'country', header: st('country'), cell: (e) => [e.country_code, e.subdivision_code].filter(Boolean).join(' · ') },
    { id: 'currency', header: st('currency'), hideBelow: 'md', cell: (e) => e.currency_code },
    { id: 'status', header: st('status'), cell: (e) => activeBadge(e.status) },
    { id: 'edit', header: <span className="sr-only">{st('edit')}</span>, align: 'end', cell: (e) => edit(e.name, () => setEntity(e)) },
  ];
  const locationColumns: DataTableColumn<WorkLocation>[] = [
    { id: 'name', header: st('name'), primary: true, cell: (l) => <div><span className="font-medium text-fg">{l.name}</span>{l.code && <span className="block text-xs text-fg-muted">{l.code}</span>}</div> },
    { id: 'entity', header: st('legalEntity'), hideBelow: 'md', cell: (l) => l.legal_entity?.name ?? '—' },
    { id: 'where', header: st('jurisdiction'), cell: (l) => `${l.country_code} · ${l.subdivision_code}${l.is_remote ? ` · ${st('remote')}` : ''}` },
    { id: 'tz', header: st('timezone'), hideBelow: 'lg', cell: (l) => l.timezone },
    { id: 'status', header: st('status'), cell: (l) => activeBadge(l.status) },
    { id: 'edit', header: <span className="sr-only">{st('edit')}</span>, align: 'end', cell: (l) => edit(l.name, () => setLocation(l)) },
  ];
  if (entities.error instanceof ApiNotConnectedError) return <NotConnected />;
  return (
    <div className="space-y-6">
      <Card title={st('legalEntities')} headingLevel={2} padding="none" description={st('legalEntitiesHint')} actions={manage && <Button onClick={() => setEntity('new')}>{st('newEntity')}</Button>}>
        <DataTable caption={st('legalEntities')} hideCaption columns={entityColumns} rows={entities.data ?? []} getRowId={(e) => e.id}
          loading={entities.loading && entities.data === undefined} error={entities.error ? errorMessage(entities.error, st('loadError')) : null} onRetry={entities.reload}
          empty={<EmptyState title={st('noEntities')} />} />
      </Card>
      <Card title={st('workLocations')} headingLevel={2} padding="none" description={st('workLocationsHint')}
        actions={manage && <Button disabled={!entities.data?.some((e) => e.status === 'active')} onClick={() => setLocation('new')}>{st('newLocation')}</Button>}>
        <DataTable caption={st('workLocations')} hideCaption columns={locationColumns} rows={locations.data ?? []} getRowId={(l) => l.id}
          loading={locations.loading && locations.data === undefined} error={locations.error ? errorMessage(locations.error, st('loadError')) : null} onRetry={locations.reload}
          empty={<EmptyState title={st('noLocations')} />} />
      </Card>
      {entity && <EntityDialog entity={entity === 'new' ? null : entity} onClose={() => setEntity(null)} onSaved={() => { setEntity(null); entities.reload(); locations.reload(); }} />}
      {location && <LocationDialog location={location === 'new' ? null : location} entities={(entities.data ?? []).filter((e) => e.status === 'active')}
        onClose={() => setLocation(null)} onSaved={() => { setLocation(null); locations.reload(); }} />}
    </div>
  );
}

const blankToNull = <T extends Record<string, unknown>>(o: T) => Object.fromEntries(Object.entries(o).map(([k, v]) => [k, v === '' ? null : v])) as T;

function EntityDialog({ entity, onClose, onSaved }: { entity: LegalEntity | null; onClose: () => void; onSaved: () => void }) {
  const { st, t } = useSettingsT();
  const source = useSettingsSource();
  const { toast } = useToast();
  const [input, setInput] = useState({
    name: entity?.name ?? '', legal_name: entity?.legal_name ?? '', country_code: entity?.country_code ?? '', subdivision_code: entity?.subdivision_code ?? '',
    currency_code: entity?.currency_code ?? '', default_language_code: entity?.default_language_code ?? '', registration_number: entity?.registration_number ?? '',
    tax_identifier: entity?.tax_identifier ?? '', address_line1: entity?.address_line1 ?? '', city: entity?.city ?? '', postal_code: entity?.postal_code ?? '', status: entity?.status ?? 'active',
  });
  const countries = useResource(useCallback((signal: AbortSignal) => source.countries(signal), [source]));
  const currencies = useResource(useCallback((signal: AbortSignal) => source.currencies(signal), [source]));
  const code = input.country_code;
  const country = useResource(useCallback((signal: AbortSignal) => (code ? source.country(code, signal) : Promise.resolve(null)), [source, code]));
  const { error, busy, save } = useSave();
  const fields = validationErrors(error);
  const set = (patch: Partial<typeof input>) => setInput({ ...input, ...patch });
  const pickCountry = (c: string) => {
    const found = countries.data?.find((x) => x.code === c);
    set({ country_code: c, subdivision_code: '', currency_code: found?.default_currency_code ?? '', default_language_code: found?.default_language_code ?? '' });
  };
  const valid = input.name.trim() && input.legal_name.trim() && input.country_code && input.currency_code && input.default_language_code;
  const deactivating = entity?.status === 'active' && input.status === 'inactive';
  const [confirming, setConfirming] = useState(false);
  const submit = () => save(async () => {
          const { country_code, status, ...rest } = blankToNull(input);
          const body: LegalEntityInput = entity ? { ...rest, status } : { ...rest, country_code };
          if (entity && !('registration_number' in entity)) { delete body.registration_number; delete body.tax_identifier; }
          await source.saveLegalEntity(body, entity?.id);
          toast({ title: st('saved'), tone: 'success' });
          onSaved();
        });
  return (
    <Modal open onClose={onClose} size="lg" title={st(entity ? 'editEntity' : 'newEntity')}
      footer={<><Button variant="secondary" onClick={onClose}>{t('ui.cancel')}</Button>
        <Button loading={busy} disabled={!valid} onClick={() => (deactivating ? setConfirming(true) : void submit())}>{st('save')}</Button></>}>
      <div className="grid gap-4 sm:grid-cols-2">
        <Input label={st('name')} required maxLength={160} value={input.name} error={fields.name} onChange={(e) => set({ name: e.target.value })} />
        <Input label={st('legalName')} required maxLength={200} value={input.legal_name} error={fields.legal_name} onChange={(e) => set({ legal_name: e.target.value })} />
        <Select label={st('country')} required disabled={entity !== null} hint={entity ? st('countryFixed') : undefined} value={input.country_code} error={fields.country_code}
          options={(countries.data ?? []).map((c) => ({ value: c.code, label: c.name }))} onChange={(e) => pickCountry(e.target.value)} />
        <Select label={country.data?.subdivision_label ?? st('subdivision')} optional value={input.subdivision_code} error={fields.subdivision_code} placeholder={st('none')}
          options={(country.data?.subdivisions ?? []).map((s) => ({ value: s.code, label: s.name }))} onChange={(e) => set({ subdivision_code: e.target.value })} />
        <Select label={st('currency')} required value={input.currency_code} error={fields.currency_code}
          options={(currencies.data ?? []).map((c) => ({ value: c.code, label: `${c.code} · ${c.name}` }))} onChange={(e) => set({ currency_code: e.target.value })} />
        <Select label={st('language')} required value={input.default_language_code} error={fields.default_language_code}
          options={(country.data?.languages ?? []).map((l) => ({ value: l.code, label: l.code }))} onChange={(e) => set({ default_language_code: e.target.value })} />
        {(entity === null || 'registration_number' in entity) && <>
          <Input label={st('registrationNumber')} optional maxLength={64} value={input.registration_number ?? ''} error={fields.registration_number} onChange={(e) => set({ registration_number: e.target.value })} />
          <Input label={st('taxIdentifier')} optional maxLength={64} value={input.tax_identifier ?? ''} error={fields.tax_identifier} onChange={(e) => set({ tax_identifier: e.target.value })} />
        </>}
        <Input label={st('address')} optional maxLength={200} value={input.address_line1 ?? ''} error={fields.address_line1} onChange={(e) => set({ address_line1: e.target.value })} />
        <Input label={st('city')} optional maxLength={120} value={input.city ?? ''} error={fields.city} onChange={(e) => set({ city: e.target.value })} />
        <Input label={st('postalCode')} optional maxLength={20} value={input.postal_code ?? ''} error={fields.postal_code} onChange={(e) => set({ postal_code: e.target.value })} />
        {entity && <Select label={st('status')} value={input.status} options={[{ value: 'active', label: st('active') }, { value: 'inactive', label: st('inactive') }]}
          onChange={(e) => set({ status: e.target.value as 'active' | 'inactive' })} />}
        <ConfirmationDialog open={confirming} title={st('deactivateTitle', { name: entity?.name ?? '' })} description={st('deactivateConfirm')} confirmLabel={st('deactivate')}
          onCancel={() => setConfirming(false)} onConfirm={async () => { setConfirming(false); await submit(); }} />
        <FormError message={error ? formError(error, st('saveFailed')) : null} />
      </div>
    </Modal>
  );
}

function LocationDialog({ location, entities, onClose, onSaved }: { location: WorkLocation | null; entities: LegalEntity[]; onClose: () => void; onSaved: () => void }) {
  const { st, t } = useSettingsT();
  const source = useSettingsSource();
  const { toast } = useToast();
  const [input, setInput] = useState({
    legal_entity_id: location?.legal_entity?.id ?? entities[0]?.id ?? '', name: location?.name ?? '', code: location?.code ?? '', subdivision_code: location?.subdivision_code ?? '',
    timezone: location?.timezone ?? '', city: location?.city ?? '', address_line1: location?.address_line1 ?? '', postal_code: location?.postal_code ?? '',
    is_remote: location?.is_remote ?? false, status: location?.status ?? 'active',
  });
  const code = location?.country_code ?? entities.find((e) => e.id === input.legal_entity_id)?.country_code ?? '';
  const country = useResource(useCallback((signal: AbortSignal) => (code ? source.country(code, signal) : Promise.resolve(null)), [source, code]));
  const { error, busy, save } = useSave();
  const fields = validationErrors(error);
  const set = (patch: Partial<typeof input>) => setInput({ ...input, ...patch });
  const valid = input.name.trim() && input.timezone && (location !== null || (input.legal_entity_id && input.subdivision_code));
  const deactivating = location?.status === 'active' && input.status === 'inactive';
  const [confirming, setConfirming] = useState(false);
  const submit = () => save(async () => {
          const { legal_entity_id, subdivision_code, status, ...rest } = blankToNull(input);
          const body: WorkLocationInput = location ? { ...rest, status } : { ...rest, legal_entity_id: legal_entity_id ?? undefined, subdivision_code: subdivision_code ?? undefined };
          await source.saveWorkLocation(body, location?.id);
          toast({ title: st('saved'), tone: 'success' });
          onSaved();
        });
  return (
    <Modal open onClose={onClose} size="lg" title={st(location ? 'editLocation' : 'newLocation')}
      footer={<><Button variant="secondary" onClick={onClose}>{t('ui.cancel')}</Button>
        <Button loading={busy} disabled={!valid} onClick={() => (deactivating ? setConfirming(true) : void submit())}>{st('save')}</Button></>}>
      <div className="grid gap-4 sm:grid-cols-2">
        <Select label={st('legalEntity')} required disabled={location !== null} value={input.legal_entity_id} error={fields.legal_entity_id}
          options={(location?.legal_entity ? [location.legal_entity] : entities).map((e) => ({ value: e.id, label: e.name }))}
          onChange={(e) => set({ legal_entity_id: e.target.value, subdivision_code: '', timezone: '' })} />
        <Input label={st('name')} required maxLength={160} value={input.name} error={fields.name} onChange={(e) => set({ name: e.target.value })} />
        <Input label={st('code')} optional maxLength={40} value={input.code ?? ''} error={fields.code} onChange={(e) => set({ code: e.target.value })} />
        <Select label={country.data?.subdivision_label ?? st('subdivision')} required disabled={location !== null} value={input.subdivision_code} error={fields.subdivision_code}
          options={(country.data?.subdivisions ?? []).map((s) => ({ value: s.code, label: s.name }))} onChange={(e) => set({ subdivision_code: e.target.value })} />
        <Select label={st('timezone')} required value={input.timezone} error={fields.timezone}
          options={(country.data?.timezones ?? []).map((z) => ({ value: z, label: z }))} onChange={(e) => set({ timezone: e.target.value })} />
        <Input label={st('city')} optional maxLength={120} value={input.city ?? ''} error={fields.city} onChange={(e) => set({ city: e.target.value })} />
        <Input label={st('address')} optional maxLength={200} value={input.address_line1 ?? ''} error={fields.address_line1} onChange={(e) => set({ address_line1: e.target.value })} />
        <Input label={st('postalCode')} optional maxLength={20} value={input.postal_code ?? ''} error={fields.postal_code} onChange={(e) => set({ postal_code: e.target.value })} />
        <Checkbox label={st('remote')} checked={input.is_remote} onChange={(e) => set({ is_remote: e.target.checked })} />
        {location && <Select label={st('status')} value={input.status} options={[{ value: 'active', label: st('active') }, { value: 'inactive', label: st('inactive') }]}
          onChange={(e) => set({ status: e.target.value as 'active' | 'inactive' })} />}
        <ConfirmationDialog open={confirming} title={st('deactivateTitle', { name: location?.name ?? '' })} description={st('deactivateConfirm')} confirmLabel={st('deactivate')}
          onCancel={() => setConfirming(false)} onConfirm={async () => { setConfirming(false); await submit(); }} />
        <FormError message={error ? formError(error, st('saveFailed')) : null} />
      </div>
    </Modal>
  );
}

/* ---------------- My account ---------------- */

function AccountTab() {
  const [tokensVersion, setTokensVersion] = useState(0);
  return <div className="space-y-6"><PasswordCard onChanged={() => setTokensVersion((v) => v + 1)} /><MfaCard /><TokensCard key={tokensVersion} /></div>;
}

function PasswordCard({ onChanged }: { onChanged: () => void }) {
  const { st } = useSettingsT();
  const source = useSettingsSource();
  const { toast } = useToast();
  const blank = { current_password: '', password: '', password_confirmation: '' };
  const [input, setInput] = useState(blank);
  const { error, busy, save } = useSave();
  const fields = validationErrors(error);
  return (
    <Card title={st('password')} headingLevel={2} description={st('passwordHint')}>
      <form className="grid items-end gap-4 sm:grid-cols-3" onSubmit={(e) => {
        e.preventDefault();
        void save(async () => { await source.changePassword(input); setInput(blank); onChanged(); toast({ title: st('passwordChanged'), tone: 'success' }); });
      }}>
        <Input label={st('currentPassword')} type="password" autoComplete="current-password" required value={input.current_password} error={fields.current_password} onChange={(e) => setInput({ ...input, current_password: e.target.value })} />
        <Input label={st('newPassword')} type="password" autoComplete="new-password" required value={input.password} error={fields.password} onChange={(e) => setInput({ ...input, password: e.target.value })} />
        <Input label={st('confirmPassword')} type="password" autoComplete="new-password" required value={input.password_confirmation} error={fields.password_confirmation} onChange={(e) => setInput({ ...input, password_confirmation: e.target.value })} />
        <div><Button type="submit" loading={busy} disabled={!input.current_password || !input.password}>{st('changePassword')}</Button></div>
        <FormError message={error ? formError(error, st('saveFailed')) : null} />
      </form>
    </Card>
  );
}

function MfaCard() {
  const { st } = useSettingsT();
  const source = useSettingsSource();
  const { me } = useSession();
  const [code, setCode] = useState('');
  const [codes, setCodes] = useState<string[] | null>(null);
  const { error, busy, save } = useSave();
  const enabled = me?.user.mfa_enabled ?? false;
  return (
    <Card title={st('mfaTitle')} headingLevel={2} description={st(enabled ? 'mfaOnHint' : 'mfaOffHint')}
      actions={<Badge tone={enabled ? 'success' : 'neutral'}>{st(enabled ? 'on' : 'off')}</Badge>}>
      {enabled && (codes ? (
        <div>
          <p className="text-sm text-fg-secondary">{st('newCodesHint')}</p>
          <ul aria-label={st('newCodes')} className="mt-3 grid grid-cols-2 gap-2 rounded-md border border-line bg-surface-muted p-4 font-mono text-sm text-fg sm:grid-cols-5">
            {codes.map((c) => <li key={c}>{c}</li>)}
          </ul>
          <Button className="mt-3" variant="secondary" onClick={() => setCodes(null)}>{st('done')}</Button>
        </div>
      ) : (
        <form className="grid items-end gap-4 sm:grid-cols-[minmax(0,16rem)_auto]" onSubmit={(e) => {
          e.preventDefault();
          void save(async () => { setCodes(await source.regenerateRecoveryCodes(code.replace(/\s/g, ''))); setCode(''); });
        }}>
          <Input label={st('authCode')} hint={st('regenerateHint')} inputMode="numeric" autoComplete="one-time-code" maxLength={6} value={code}
            error={validationErrors(error).code} onChange={(e) => setCode(e.target.value)} className="font-mono tracking-[0.3em]" />
          <div><Button type="submit" variant="secondary" loading={busy} disabled={!/^\d{6}$/.test(code.trim())}>{st('regenerate')}</Button></div>
          <FormError message={error ? formError(error, st('saveFailed')) : null} />
        </form>
      ))}
    </Card>
  );
}

function TokensCard() {
  const { st, when } = useSettingsT();
  const source = useSettingsSource();
  const tokens = useResource(useCallback((signal: AbortSignal) => source.tokens(signal), [source]));
  const revoke = useAction();
  const [revoking, setRevoking] = useState<ApiToken | null>(null);
  const columns: DataTableColumn<ApiToken>[] = [
    { id: 'name', header: st('name'), primary: true, cell: (k) => <span className="font-medium text-fg">{k.name}{k.current && <Badge className="ms-2" size="sm">{st('thisToken')}</Badge>}</span> },
    { id: 'abilities', header: st('abilities'), hideBelow: 'md', cell: (k) => k.abilities.join(', ') },
    { id: 'used', header: st('lastUsed'), cell: (k) => when(k.last_used_at) },
    { id: 'expires', header: st('expires'), hideBelow: 'lg', cell: (k) => when(k.expires_at) },
    { id: 'revoke', header: <span className="sr-only">{st('revoke')}</span>, align: 'end', cell: (k) => (
      <Button size="sm" variant="tertiary" loading={revoke.busy === String(k.id)} aria-label={st('revokeNamed', { name: k.name })}
        onClick={() => setRevoking(k)}>{st('revoke')}</Button>
    ) },
  ];
  return (
    <Card title={st('tokens')} headingLevel={2} padding="none" description={st('tokensHint')}>
      {tokens.error instanceof ApiNotConnectedError ? <div className="p-4"><NotConnected /></div> : (
        <DataTable caption={st('tokens')} hideCaption columns={columns} rows={tokens.data ?? []} getRowId={(k) => String(k.id)}
          loading={tokens.loading && tokens.data === undefined} error={tokens.error ? errorMessage(tokens.error, st('loadError')) : null} onRetry={tokens.reload}
          empty={<EmptyState title={st('noTokens')} />} />
      )}
      <ConfirmationDialog open={revoking !== null} title={st('revokeTitle', { name: revoking?.name ?? '' })} description={st('revokeConfirm')} confirmLabel={st('revoke')}
        onCancel={() => setRevoking(null)}
        onConfirm={async () => { if (revoking) await revoke.run(String(revoking.id), () => source.revokeToken(revoking.id), st('revoked')).then(tokens.reload); setRevoking(null); }} />
    </Card>
  );
}
