import { createContext, useContext } from 'react';
import { ApiNotConnectedError } from './dashboard';
import { apiDownload, apiRequest, ensureCsrfCookie, fromPaginator as toPage, type LaravelPaginator as RawPage } from './http';
import type { Page } from './employees';
import type { BillingSummary, Usage } from './billing';

/** Mirrors the platform administration API (backend docs/architecture/platform.md). Platform staff only; amounts are in minor units. */
export type TenantStatus = 'active' | 'suspended' | 'deleted';
export interface TenantRow {
  id: string; name: string; slug: string; country_code: string; status: TenantStatus; created_at: string | null;
  subscription: { plan: string | null; status: string; provider: string; access: 'full' | 'read_only'; trial_ends_at: string | null } | null;
}
export interface TenantDetail extends TenantRow {
  subscription_detail: (BillingSummary & {
    provider: string; billing_interval: string | null; currency: string | null; current_period_end: string | null; cancel_at_period_end: boolean; version: number;
  }) | null;
  users: number;
  usage: Usage | null;
  support_access: { expires_at: string; include_payroll: boolean } | null;
  deletion_scheduled_for: string | null;
  legal_hold: boolean;
}
export interface TenantQuery { search: string; status: string; plan: string; subscription_status: string }
export interface NewTenant { name: string; slug: string; country_code: string; owner_name: string; owner_email: string }
export type SubscriptionChange = { action: 'extend_trial'; days: number; reason: string; version: number }
  | { action: 'assign_plan'; plan: string; ends_at: string | null; reason: string; version: number };

export interface SubscriptionOverview {
  by_status: Record<string, number>; by_provider: Record<string, number>; by_plan: Record<string, number>;
  /** Monthly recurring revenue per currency; never converted between currencies. */
  mrr_minor: Record<string, number>;
  trials_ending_7d: number; cancel_scheduled: number; tenants: { active: number; suspended: number };
}
export interface PlatformPlan { id: string; code: string; name: string; employee_limit: number | null; ai_requests_monthly: number | null; rank: number; active: boolean; subscriptions: number }
export interface UsageRow {
  id: string; name: string; status: TenantStatus; plan: string | null; subscription_status: string | null;
  employees: { used: number; limit: number | null }; ai_requests: { used: number; limit: number | null; period: string }; storage_bytes: number;
}
export type UsageSort = 'name' | 'employees' | 'ai_requests' | 'storage_bytes';

export type CheckStatus = 'ok' | 'degraded' | 'down';
export interface HealthReport {
  status: CheckStatus; checked_at: string;
  checks: {
    database: { status: CheckStatus; latency_ms: number | null };
    cache: { status: CheckStatus; latency_ms: number | null };
    queue: { status: CheckStatus; connection: string; pending: number | null; failed: number; failed_24h: number };
    scheduler: { status: CheckStatus; last_run_at: string | null };
    webhooks: { status: CheckStatus; processed_24h: number; ignored_24h: number; unmatched_24h: number; rejected_24h: number; last_received_at: string | null };
    data: { status: CheckStatus; exports_failed_24h: number; deletions_scheduled: number; deletions_in_progress: number };
  };
}

export interface AuditEntry {
  id: string; created_at: string; category: string; event: string; outcome: 'success' | 'failure';
  actor: { id: string; name: string } | null; actor_type: string | null; subject: { type: string; id: string | null } | null;
  metadata: Record<string, unknown> | null; ip_address: string | null; request_id: string | null;
}
export interface AuditQuery { event: string; outcome: string; from: string; to: string }

export type SupportSection = 'overview' | 'users' | 'employees' | 'audit' | 'payroll';
export interface SupportStatus { active: boolean; expires_at: string | null; include_payroll: boolean; reason: string | null; sections: SupportSection[] }

export interface SupportOverview {
  organization: { id: string; name: string; country_code: string; status: string };
  subscription: BillingSummary | null; usage: Usage;
  legal_entities: Array<{ id: string; name: string; country_code: string; currency_code: string | null; status: string }>;
  departments: number; employees_by_status: Record<string, number>;
}

export interface PlatformSource {
  tenants(query: TenantQuery, page: number, signal?: AbortSignal): Promise<Page<TenantRow>>;
  tenant(id: string, signal?: AbortSignal): Promise<TenantDetail>;
  createTenant(input: NewTenant): Promise<TenantRow>;
  setStatus(id: string, status: 'active' | 'suspended', reason: string): Promise<TenantRow>;
  changeSubscription(id: string, change: SubscriptionChange): Promise<unknown>;
  plans(signal?: AbortSignal): Promise<PlatformPlan[]>;
  overview(signal?: AbortSignal): Promise<SubscriptionOverview>;
  usage(search: string, sort: UsageSort, page: number, signal?: AbortSignal): Promise<Page<UsageRow>>;
  health(signal?: AbortSignal): Promise<HealthReport>;
  audit(query: AuditQuery, page: number, signal?: AbortSignal): Promise<Page<AuditEntry>>;
  exportAudit(query: AuditQuery): Promise<void>;
  support(id: string, signal?: AbortSignal): Promise<SupportStatus>;
  /** Read-only view of one section of a tenant, available only while its owner's grant is active. */
  supportSection(id: string, section: SupportSection, page: number, signal?: AbortSignal): Promise<SupportOverview | Page<Record<string, unknown>>>;
}

const v1 = '/api/v1/platform';
const data = async <T,>(p: Promise<{ data: T }>) => (await p).data;
async function send<T>(method: 'POST' | 'PATCH', path: string, body: unknown): Promise<T> {
  await ensureCsrfCookie();
  return data(apiRequest<{ data: T }>(method, path, { body }));
}
const auditQuery = (q: AuditQuery) => ({ event: q.event || undefined, outcome: q.outcome || undefined, from: q.from || undefined, to: q.to || undefined });

/** The tenant API returns the full subscription under `subscription`; rename it so it cannot be confused with the list row's summary. */
async function tenantDetail(id: string, signal?: AbortSignal): Promise<TenantDetail> {
  const { data: d } = await apiRequest<{ data: Omit<TenantDetail, 'subscription_detail'> & { subscription: TenantDetail['subscription_detail'] } & Record<string, unknown> }>('GET', `${v1}/tenants/${id}`, { signal });
  const { subscription, ...rest } = d;
  return { ...(rest as Omit<TenantDetail, 'subscription_detail' | 'subscription'>), subscription: null, subscription_detail: subscription };
}

export const httpPlatformSource: PlatformSource = {
  tenants: (q, page, signal) => toPage(apiRequest('GET', `${v1}/tenants`, {
    query: { page: String(page), search: q.search || undefined, status: q.status || undefined, plan: q.plan || undefined, subscription_status: q.subscription_status || undefined }, signal,
  })),
  tenant: tenantDetail,
  createTenant: (input) => send('POST', `${v1}/tenants`, input),
  setStatus: (id, status, reason) => send('PATCH', `${v1}/tenants/${id}/status`, { status, reason }),
  changeSubscription: (id, change) => send('PATCH', `${v1}/tenants/${id}/subscription`, change),
  plans: (signal) => data(apiRequest('GET', `${v1}/plans`, { signal })),
  overview: (signal) => data(apiRequest('GET', `${v1}/subscriptions/overview`, { signal })),
  usage: (search, sort, page, signal) => toPage(apiRequest('GET', `${v1}/usage`, { query: { page: String(page), search: search || undefined, sort }, signal })),
  health: (signal) => data(apiRequest('GET', `${v1}/health`, { signal })),
  audit: (q, page, signal) => toPage(apiRequest('GET', `${v1}/audit-logs`, { query: { page: String(page), ...auditQuery(q) }, signal })),
  exportAudit: (q) => apiDownload(`${v1}/audit-logs/export`, { format: 'csv', ...auditQuery(q) }, 'platform-audit.csv'),
  support: (id, signal) => data(apiRequest('GET', `${v1}/tenants/${id}/support`, { signal })),
  supportSection: async (id, section, page, signal) => {
    const path = `${v1}/tenants/${id}/support/${section}`;
    return section === 'overview'
      ? data(apiRequest<{ data: SupportOverview }>('GET', path, { signal }))
      : toPage(apiRequest<RawPage<Record<string, unknown>>>('GET', path, { query: { page: String(page) }, signal }));
  },
};

const notConnected = () => Promise.reject(new ApiNotConnectedError());
export const disconnectedPlatformSource = Object.fromEntries(Object.keys(httpPlatformSource).map((key) => [key, notConnected])) as unknown as PlatformSource;
const PlatformSourceContext = createContext<PlatformSource>(disconnectedPlatformSource);
export const PlatformSourceProvider = PlatformSourceContext.Provider;
export const usePlatformSource = () => useContext(PlatformSourceContext);
