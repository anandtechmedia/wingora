import { createContext, useContext } from 'react';
import { ApiNotConnectedError } from './dashboard';
import { apiDownload, apiRequest, ensureCsrfCookie } from './http';

/** Mirrors the Employee Management API (docs/architecture/employees.md). Fields the caller may not see are never sent. */
export type EmployeeStatus = 'active' | 'on_leave' | 'suspended' | 'terminated';
export interface Ref { id: string; name: string }
export interface EmployeeSummary {
  id: string;
  employee_number: string;
  first_name: string;
  last_name: string;
  preferred_name: string | null;
  work_email: string | null;
  job_title: string | null;
  status: EmployeeStatus;
  employment_type: string | null;
  hire_date: string | null;
  termination_date: string | null;
  department: Ref | null;
  designation: Ref | null;
  manager: (Ref & { job_title: string | null }) | null;
  legal_entity: (Ref & { country_code: string }) | null;
  work_location: (Ref & { subdivision_code: string; timezone: string }) | null;
}
export interface EmployeeAbilities {
  update: boolean; view_personal: boolean; update_personal: boolean; view_sensitive: boolean;
  update_sensitive: boolean; view_records: boolean; manage_records: boolean;
}
export interface EmployeeDetail extends EmployeeSummary { abilities: EmployeeAbilities }
export interface Page<T> { data: T[]; meta: { current_page: number; last_page: number; per_page: number; total: number } }

export interface EmployeeQuery {
  search: string; status: string; department: string; designation: string; country: string;
  legal_entity: string; employment_type: string; sort: string; page: number;
}
export const emptyEmployeeQuery: EmployeeQuery = { search: '', status: '', department: '', designation: '', country: '', legal_entity: '', employment_type: '', sort: 'name', page: 1 };

export interface EmployeeInput {
  employee_number?: string; first_name?: string; last_name?: string; preferred_name?: string | null; work_email?: string | null;
  job_title?: string | null; status?: EmployeeStatus; employment_type?: string | null; hire_date?: string | null;
  department_id?: string | null; designation_id?: string | null; manager_id?: string | null;
  legal_entity_id?: string | null; work_location_id?: string | null; effective_date?: string | null; note?: string | null;
}
export interface Address { line1: string; line2?: string | null; city: string; region?: string | null; postal_code?: string | null; country_code: string }
export interface PersonalDetails { preferred_name: string | null; nationality: string | null; personal_email: string | null; mobile_phone: string | null; home_address: Address | null }
export interface SensitiveDetails { national_id: string | null; bank_account_number: string | null; date_of_birth: string | null }
export interface HistoryEntry { id: string; effective_date: string; change_type: string; changes: Record<string, { from: string | null; to: string | null }>; note: string | null; recorded_by: string | null; recorded_at: string | null }
export interface EmergencyContact { id: string; name: string; relationship: string; phone: string; email: string | null; is_primary: boolean }
export interface HrRecord { id: string; type: 'note' | 'disciplinary'; severity: string | null; title: string; body: string; occurred_on: string; author: string | null; created_at: string | null }
export interface EmployeeDocument { id: string; category: string; name: string; mime_type: string; size: number; uploaded_by: string | null; created_at: string | null; can_delete: boolean }
export interface PolicyStatus { id: string; title: string; version: string; summary: string | null; effective_date: string; acknowledged_at: string | null }
export interface ImportResult { rows: number; valid: number; invalid: number; dry_run: boolean; created: number; errors: { line: number; employee_number: string | null; errors: string[] }[] }
export interface LocationOption extends Ref { legal_entity_id: string | null }
export interface DirectoryOptions { departments: Ref[]; designations: Ref[]; legalEntities: (Ref & { country_code: string })[]; workLocations: LocationOption[] }

export interface EmployeeSource {
  list(query: EmployeeQuery, signal: AbortSignal): Promise<Page<EmployeeSummary>>;
  options(signal: AbortSignal): Promise<DirectoryOptions>;
  get(id: string, signal?: AbortSignal): Promise<EmployeeDetail>;
  create(input: EmployeeInput): Promise<EmployeeDetail>;
  update(id: string, input: EmployeeInput): Promise<EmployeeDetail>;
  history(id: string, signal?: AbortSignal): Promise<Page<HistoryEntry>>;
  personal(id: string, signal?: AbortSignal): Promise<PersonalDetails>;
  updatePersonal(id: string, input: Partial<PersonalDetails>): Promise<PersonalDetails>;
  sensitive(id: string): Promise<SensitiveDetails>;
  contacts(id: string, signal?: AbortSignal): Promise<EmergencyContact[]>;
  addContact(id: string, input: Omit<EmergencyContact, 'id'>): Promise<EmergencyContact>;
  removeContact(id: string, contactId: string): Promise<void>;
  records(id: string, signal?: AbortSignal): Promise<HrRecord[]>;
  addRecord(id: string, input: Pick<HrRecord, 'type' | 'severity' | 'title' | 'body' | 'occurred_on'>): Promise<HrRecord>;
  documents(id: string, signal?: AbortSignal): Promise<{ data: EmployeeDocument[]; meta: { upload_categories: string[] } }>;
  upload(id: string, file: File, category: string): Promise<void>;
  download(fileId: string, name: string): Promise<void>;
  policies(id: string, signal?: AbortSignal): Promise<PolicyStatus[]>;
  acknowledge(policyId: string): Promise<void>;
  importCsv(file: File, dryRun: boolean): Promise<ImportResult>;
  exportCsv(query: EmployeeQuery): Promise<void>;
}

const base = '/api/v1/employees';
const listQuery = (q: EmployeeQuery) => ({ search: q.search, status: q.status, department: q.department, designation: q.designation, country: q.country, legal_entity: q.legal_entity, employment_type: q.employment_type });
const data = async <T>(promise: Promise<{ data: T }>) => (await promise).data;
async function write<T>(method: 'POST' | 'PATCH' | 'DELETE', path: string, body?: unknown): Promise<T> {
  await ensureCsrfCookie();
  return apiRequest<T>(method, path, { body });
}

export const httpEmployeeSource: EmployeeSource = {
  list: (q, signal) => apiRequest('GET', base, { query: { ...listQuery(q), sort: q.sort, page: String(q.page), per_page: '25' }, signal }),
  async options(signal) {
    const query = { per_page: '100' };
    const optional = <T>(p: Promise<{ data: T[] }>) => p.then((r) => r.data, () => [] as T[]);
    const [departments, designations, legalEntities, locations] = await Promise.all([
      optional(apiRequest<{ data: Ref[] }>('GET', '/api/v1/departments', { query, signal })),
      optional(apiRequest<{ data: Ref[] }>('GET', '/api/v1/designations', { signal })),
      optional(apiRequest<{ data: (Ref & { country_code: string })[] }>('GET', '/api/v1/legal-entities', { query, signal })),
      optional(apiRequest<{ data: (Ref & { legal_entity?: { id: string } | null; legal_entity_id?: string })[] }>('GET', '/api/v1/work-locations', { query, signal })),
    ]);
    const workLocations = locations.map((l) => ({ id: l.id, name: l.name, legal_entity_id: l.legal_entity?.id ?? l.legal_entity_id ?? null }));
    return { departments, designations, legalEntities, workLocations };
  },
  get: (id, signal) => data(apiRequest('GET', `${base}/${id}`, { signal })),
  create: (input) => data(write('POST', base, input)),
  update: (id, input) => data(write('PATCH', `${base}/${id}`, input)),
  history: (id, signal) => apiRequest('GET', `${base}/${id}/history`, { query: { per_page: '100' }, signal }),
  personal: (id, signal) => data(apiRequest('GET', `${base}/${id}/personal`, { signal })),
  updatePersonal: (id, input) => data(write('PATCH', `${base}/${id}/personal`, input)),
  sensitive: (id) => data(apiRequest('GET', `${base}/${id}/sensitive`)),
  contacts: (id, signal) => data(apiRequest('GET', `${base}/${id}/emergency-contacts`, { signal })),
  addContact: (id, input) => data(write('POST', `${base}/${id}/emergency-contacts`, input)),
  removeContact: async (id, contactId) => { await write('DELETE', `${base}/${id}/emergency-contacts/${contactId}`); },
  records: (id, signal) => data(apiRequest('GET', `${base}/${id}/records`, { query: { per_page: '100' }, signal })),
  addRecord: (id, input) => data(write('POST', `${base}/${id}/records`, input)),
  documents: (id, signal) => apiRequest('GET', `${base}/${id}/documents`, { signal }),
  async upload(id, file, category) {
    const form = new FormData();
    form.append('file', file);
    form.append('category', category);
    form.append('employee_id', id);
    await write('POST', '/api/v1/files', form);
  },
  download: (fileId, name) => apiDownload(`/api/v1/files/${fileId}/download`, {}, name),
  policies: (id, signal) => data(apiRequest('GET', `${base}/${id}/policies`, { signal })),
  acknowledge: async (policyId) => { await write('POST', `/api/v1/policies/${policyId}/acknowledge`); },
  async importCsv(file, dryRun) {
    const form = new FormData();
    form.append('file', file);
    form.append('dry_run', dryRun ? '1' : '0');
    return data(write('POST', `${base}/import`, form));
  },
  exportCsv: (q) => apiDownload(`${base}/export`, listQuery(q), 'employees.csv'),
};

const notConnected = () => Promise.reject(new ApiNotConnectedError());
/** Prototype mode: no employee data at all, never invented records. */
export const disconnectedEmployeeSource: EmployeeSource = Object.fromEntries(
  Object.keys(httpEmployeeSource).map((key) => [key, notConnected]),
) as unknown as EmployeeSource;

const EmployeeSourceContext = createContext<EmployeeSource>(disconnectedEmployeeSource);
export const EmployeeSourceProvider = EmployeeSourceContext.Provider;
export const useEmployeeSource = () => useContext(EmployeeSourceContext);
