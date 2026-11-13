import { createContext, useContext } from 'react';
import { ApiNotConnectedError } from './dashboard';
import { apiRequest, ensureCsrfCookie } from './http';
import type { Page } from './employees';

/** Mirrors the organization, user, legal entity, work location and account endpoints (docs/architecture/security.md). */
export interface Organization { id: string; name: string; country_code: string; status: string; mfa_required: boolean }
export interface TenantUser { id: string; name: string; email: string; status: 'active' | 'disabled' | string; roles: string[]; mfa_enabled: boolean; last_login_at: string | null }
export interface LegalEntity {
  id: string; name: string; legal_name: string; country_code: string; subdivision_code: string | null; currency_code: string; default_language_code: string;
  address_line1: string | null; city: string | null; postal_code: string | null; status: 'active' | 'inactive'; registration_number?: string | null; tax_identifier?: string | null;
}
export type LegalEntityInput = Partial<Omit<LegalEntity, 'id'>>;
export interface WorkLocation {
  id: string; name: string; code: string | null; legal_entity?: { id: string; name: string }; holiday_calendar?: { id: string; name: string } | null;
  country_code: string; subdivision_code: string; city: string | null; address_line1: string | null; postal_code: string | null; timezone: string; is_remote: boolean; status: 'active' | 'inactive';
}
export type WorkLocationInput = Partial<Omit<WorkLocation, 'id' | 'legal_entity' | 'holiday_calendar'>> & { legal_entity_id?: string };
export interface Country { code: string; name: string; default_currency_code: string; default_language_code: string; default_timezone: string; subdivision_label: string | null; languages: { code: string; official: boolean }[] }
export interface CountryDetail extends Country { subdivisions: { code: string; name: string }[]; timezones: string[] }
export interface Currency { code: string; name: string }
export interface ApiToken { id: number; name: string; abilities: string[]; last_used_at: string | null; expires_at: string | null; current: boolean }

export interface SettingsSource {
  organization(signal?: AbortSignal): Promise<Organization>;
  updateOrganization(name: string): Promise<Organization>;
  updateSecurity(mfaRequired: boolean): Promise<Organization>;
  users(page: number, signal?: AbortSignal): Promise<Page<TenantUser>>;
  inviteUser(input: { name: string; email: string; roles: string[] }): Promise<TenantUser>;
  updateRoles(id: string, roles: string[]): Promise<TenantUser>;
  updateStatus(id: string, status: 'active' | 'disabled'): Promise<TenantUser>;
  legalEntities(signal?: AbortSignal): Promise<LegalEntity[]>;
  saveLegalEntity(input: LegalEntityInput, id?: string): Promise<LegalEntity>;
  workLocations(signal?: AbortSignal): Promise<WorkLocation[]>;
  saveWorkLocation(input: WorkLocationInput, id?: string): Promise<WorkLocation>;
  countries(signal?: AbortSignal): Promise<Country[]>;
  country(code: string, signal?: AbortSignal): Promise<CountryDetail>;
  currencies(signal?: AbortSignal): Promise<Currency[]>;
  changePassword(input: { current_password: string; password: string; password_confirmation: string }): Promise<void>;
  regenerateRecoveryCodes(code: string): Promise<string[]>;
  tokens(signal?: AbortSignal): Promise<ApiToken[]>;
  revokeToken(id: number): Promise<void>;
}

const v1 = '/api/v1';
const data = async <T,>(p: Promise<{ data: T }>) => (await p).data;
async function write<T>(method: 'POST' | 'PUT' | 'PATCH' | 'DELETE', path: string, body?: unknown): Promise<T> {
  await ensureCsrfCookie();
  return apiRequest<T>(method, path, { body });
}
const list = { per_page: '100' };

export const httpSettingsSource: SettingsSource = {
  organization: (signal) => data(apiRequest('GET', `${v1}/organization`, { signal })),
  updateOrganization: (name) => data(write('PATCH', `${v1}/organization`, { name })),
  updateSecurity: (mfa_required) => data(write('PATCH', `${v1}/organization/security`, { mfa_required })),
  users: (page, signal) => apiRequest('GET', `${v1}/users`, { query: { page: String(page) }, signal }),
  inviteUser: (input) => data(write('POST', `${v1}/users`, input)),
  updateRoles: (id, roles) => data(write('PUT', `${v1}/users/${id}/roles`, { roles })),
  updateStatus: (id, status) => data(write('PATCH', `${v1}/users/${id}/status`, { status })),
  legalEntities: (signal) => data(apiRequest('GET', `${v1}/legal-entities`, { query: list, signal })),
  saveLegalEntity: (input, id) => data(id ? write('PATCH', `${v1}/legal-entities/${id}`, input) : write('POST', `${v1}/legal-entities`, input)),
  workLocations: (signal) => data(apiRequest('GET', `${v1}/work-locations`, { query: list, signal })),
  saveWorkLocation: (input, id) => data(id ? write('PATCH', `${v1}/work-locations/${id}`, input) : write('POST', `${v1}/work-locations`, input)),
  countries: (signal) => data(apiRequest('GET', `${v1}/reference/countries`, { signal })),
  country: (code, signal) => data(apiRequest('GET', `${v1}/reference/countries/${code}`, { signal })),
  currencies: (signal) => data(apiRequest('GET', `${v1}/reference/currencies`, { signal })),
  changePassword: async (input) => { await write('PUT', `${v1}/auth/password`, input); },
  regenerateRecoveryCodes: async (code) => (await write<{ recovery_codes: string[] }>('POST', `${v1}/auth/mfa/recovery-codes`, { code })).recovery_codes,
  tokens: (signal) => data(apiRequest('GET', `${v1}/auth/tokens`, { signal })),
  revokeToken: async (id) => { await write('DELETE', `${v1}/auth/tokens/${id}`); },
};

const notConnected = () => Promise.reject(new ApiNotConnectedError());
export const disconnectedSettingsSource: SettingsSource = Object.fromEntries(
  Object.keys(httpSettingsSource).map((key) => [key, notConnected]),
) as unknown as SettingsSource;
const SettingsSourceContext = createContext<SettingsSource>(disconnectedSettingsSource);
export const SettingsSourceProvider = SettingsSourceContext.Provider;
export const useSettingsSource = () => useContext(SettingsSourceContext);
