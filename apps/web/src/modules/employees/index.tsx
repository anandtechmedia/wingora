import { useCallback, useMemo, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router';
import { Permission as P } from '@wingora/permissions';
import { ApiNotConnectedError } from '@/api/dashboard';
import { emptyEmployeeQuery, useEmployeeSource, type DirectoryOptions, type EmployeeQuery, type EmployeeSummary } from '@/api/employees';
import { Button, Card, DataTable, EmptyState, Input, Pagination, SearchIcon, Select, UsersIcon, useToast, type DataTableColumn } from '@/components/ui';
import { useSession } from '@/auth/session';
import { useI18n } from '@/i18n/i18n';
import { PageHeader } from '@/shell/page-header';
import { EmployeeForm } from './employee-form';
import { ImportDialog } from './import-dialog';
import { EmployeeProfile } from './profile';
import { displayName, errorMessage, StatusBadge, useResource } from './shared';

const COUNTRIES = ['US', 'AE', 'CA', 'MX'] as const;
const STATUSES = ['active', 'on_leave', 'suspended', 'terminated'] as const;
const noOptions: DirectoryOptions = { departments: [], designations: [], legalEntities: [], workLocations: [] };

export default function Employees() {
  const { recordId } = useParams();
  return recordId ? <EmployeeProfile id={recordId} /> : <Directory />;
}

/** Server-side search, filters, sorting and pagination; the API returns only employees the user may see. */
function Directory() {
  const { t } = useI18n();
  const { permissions } = useSession();
  const source = useEmployeeSource();
  const navigate = useNavigate();
  const { toast } = useToast();
  const [query, setQuery] = useState<EmployeeQuery>(emptyEmployeeQuery);
  const [search, setSearch] = useState('');
  const [dialog, setDialog] = useState<'create' | 'import' | null>(null);
  const list = useResource(useCallback((signal: AbortSignal) => source.list(query, signal), [source, query]));
  const options = useResource(useCallback((signal: AbortSignal) => source.options(signal), [source]));
  const can = (p: string) => permissions.has(p);
  const opts = options.data ?? noOptions;
  const filtered = JSON.stringify({ ...query, page: 1, sort: 'name' }) !== JSON.stringify(emptyEmployeeQuery);
  const update = (patch: Partial<EmployeeQuery>) => setQuery((q) => {
    const next = { ...q, page: 1, ...patch };
    return (Object.keys(next) as (keyof EmployeeQuery)[]).every((k) => next[k] === q[k]) ? q : next;
  });

  const columns = useMemo<DataTableColumn<EmployeeSummary>[]>(() => [
    { id: 'name', header: t('employees.name'), sortable: true, primary: true, cell: (e) => (
      <div className="min-w-0">
        <Link to={`/employees/${e.id}`} className="font-medium text-fg-link hover:underline">{displayName(e)}</Link>
        <div className="truncate text-xs text-fg-muted">{e.job_title ?? e.designation?.name ?? ''}</div>
      </div>
    ) },
    { id: 'employee_number', header: t('employees.number'), sortable: true, cell: (e) => e.employee_number },
    { id: 'department', header: t('employees.department'), hideBelow: 'md', cell: (e) => e.department?.name ?? '—' },
    { id: 'entity', header: t('employees.legalEntity'), hideBelow: 'lg', cell: (e) => e.legal_entity?.name ?? '—' },
    { id: 'manager', header: t('employees.manager'), hideBelow: 'lg', cell: (e) => e.manager?.name ?? '—' },
    { id: 'status', header: t('employees.status'), cell: (e) => <StatusBadge status={e.status} /> },
  ], [t]);

  if (list.error instanceof ApiNotConnectedError) {
    return (
      <>
        <PageHeader title={t('nav.module.employees')} />
        <Card padding="none"><EmptyState headingLevel={2} icon={<UsersIcon size={22} />} title={t('employees.notConnectedTitle')} description={t('employees.notConnectedDescription')} /></Card>
      </>
    );
  }

  const sortId = query.sort.replace(/^-/, '');
  const exportCsv = () => source.exportCsv(query).catch((e: unknown) => toast({ title: errorMessage(e, t('employees.exportFailed')), tone: 'danger' }));

  return (
    <>
      <PageHeader title={t('nav.module.employees')} description={t('employees.subtitle')} actions={<div className="flex flex-wrap gap-2">
        {can(P.EmployeesExport) && <Button variant="secondary" onClick={exportCsv}>{t('employees.export')}</Button>}
        {can(P.EmployeesImport) && <Button variant="secondary" onClick={() => setDialog('import')}>{t('employees.import')}</Button>}
        {can(P.EmployeesManage) && <Button onClick={() => setDialog('create')}>{t('employees.add')}</Button>}
      </div>} />

      <Card className="mb-4">
        <form role="search" aria-label={t('employees.filters')} className="grid gap-3 sm:grid-cols-2 lg:grid-cols-6"
          onSubmit={(e) => { e.preventDefault(); update({ search: search.trim() }); }}>
          <div className="sm:col-span-2">
            <Input label={t('employees.search')} placeholder={t('employees.searchPlaceholder')} type="search" leading={<SearchIcon size={16} />}
              value={search} onChange={(e) => setSearch(e.target.value)} onBlur={() => update({ search: search.trim() })}
              onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); update({ search: search.trim() }); } }} />
          </div>
          <Select label={t('employees.status')} placeholder={t('employees.allStatuses')} value={query.status} onChange={(e) => update({ status: e.target.value })}
            options={STATUSES.map((s) => ({ value: s, label: t(`employees.status.${s}`) }))} />
          <Select label={t('employees.department')} placeholder={t('employees.allDepartments')} value={query.department} onChange={(e) => update({ department: e.target.value })}
            options={opts.departments.map((d) => ({ value: d.id, label: d.name }))} />
          <Select label={t('employees.designation')} placeholder={t('employees.allDesignations')} value={query.designation} onChange={(e) => update({ designation: e.target.value })}
            options={opts.designations.map((d) => ({ value: d.id, label: d.name }))} />
          <Select label={t('employees.country')} placeholder={t('employees.allCountries')} value={query.country} onChange={(e) => update({ country: e.target.value, legal_entity: '' })}
            options={COUNTRIES.map((c) => ({ value: c, label: c }))} />
          <Select label={t('employees.legalEntity')} placeholder={t('employees.allEntities')} value={query.legal_entity} onChange={(e) => update({ legal_entity: e.target.value })}
            options={opts.legalEntities.filter((l) => !query.country || l.country_code === query.country).map((l) => ({ value: l.id, label: l.name }))} />
          <Input label={t('employees.employmentType')} value={query.employment_type} onChange={(e) => update({ employment_type: e.target.value.trim() })} />
          {filtered && <div className="flex items-end"><Button variant="tertiary" onClick={() => { setSearch(''); setQuery(emptyEmployeeQuery); }}>{t('employees.resetFilters')}</Button></div>}
        </form>
      </Card>

      <Card padding="none">
        <DataTable caption={t('employees.caption')} hideCaption columns={columns} rows={list.data?.data ?? []} getRowId={(e) => e.id}
          loading={list.loading} error={list.error ? errorMessage(list.error, t('employees.loadError')) : null} onRetry={list.reload}
          sort={{ columnId: sortId, direction: query.sort.startsWith('-') ? 'desc' : 'asc' }}
          onSortChange={(s) => update({ sort: `${s.direction === 'desc' ? '-' : ''}${s.columnId}` })}
          empty={<EmptyState icon={<UsersIcon size={22} />} title={t(filtered ? 'employees.filteredEmpty' : 'employees.emptyTitle')} description={filtered ? undefined : t('employees.emptyDescription')} />} />
        {list.data && list.data.meta.last_page > 1 && (
          <div className="border-t border-line p-3">
            <Pagination page={list.data.meta.current_page} pageCount={list.data.meta.last_page} total={list.data.meta.total} pageSize={list.data.meta.per_page}
              onPageChange={(page) => setQuery((q) => ({ ...q, page }))} />
          </div>
        )}
      </Card>

      {dialog === 'create' && <EmployeeForm open onClose={() => setDialog(null)} options={opts} onSaved={(e) => navigate(`/employees/${e.id}`)} />}
      {dialog === 'import' && <ImportDialog open onClose={() => setDialog(null)} onImported={list.reload} />}
    </>
  );
}
