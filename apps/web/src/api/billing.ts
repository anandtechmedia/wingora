import { createContext, useContext } from 'react';
import { ApiNotConnectedError } from './dashboard';
import { apiDownload, apiRequest, ensureCsrfCookie, fromPaginator } from './http';
import type { Page } from './employees';

/** Mirrors the Billing and Account APIs (docs/architecture/billing.md, platform.md). Amounts are in minor units. */
export type Interval = 'monthly' | 'annual';
export type AccessReason = 'trial_expired' | 'term_ended' | 'payment_failed' | 'payment_overdue' | 'canceled';
export interface Access { mode: 'full' | 'read_only'; reason: AccessReason | null }
export interface PlanRef { code: string; name: string }
/** Returned on `/me` as `billing`, and the first fields of the subscription. */
export interface BillingSummary { plan: PlanRef; status: string; access: Access; features: string[]; trial_ends_at: string | null }
export interface Usage {
  employees: { used: number; limit: number | null };
  ai_requests: { used: number; limit: number | null; period: string };
  storage_bytes: number;
}
export interface Subscription extends BillingSummary {
  provider: 'trial' | 'stripe' | 'manual'; interval: Interval | null; currency: string; current_period_end: string | null;
  cancel_at_period_end: boolean; canceled_at: string | null; grace_ends_at: string | null;
  pending_change: { plan: PlanRef; interval: Interval | null; effective_at: string } | null;
  limits: { employees: number | null; ai_requests_monthly: number | null }; usage: Usage;
  payments_enabled: boolean; has_payment_account: boolean; version: number;
}
export interface Plan {
  code: string; name: string; description: string | null; employee_limit: number | null; ai_requests_monthly: number | null;
  features: string[]; rank: number; currency: string; prices: Record<Interval, { amount: number | null; available: boolean }>;
}
export interface Invoice {
  id: string; number: string | null; status: 'draft' | 'open' | 'paid' | 'void' | 'uncollectible'; currency: string; amount_due: number; amount_paid: number;
  period_start: string | null; period_end: string | null; issued_at: string | null; paid_at: string | null; hosted_invoice_url: string | null; invoice_pdf_url: string | null;
}
export interface SupportGrant {
  id: string; reason: string; include_payroll: boolean; active: boolean; granted_by: string | null; created_at: string | null;
  expires_at: string; revoked_at: string | null; last_used_at: string | null;
}
export type ExportStatus = 'queued' | 'running' | 'ready' | 'failed' | 'expired';
export interface DataExport { id: string; status: ExportStatus; size: number | null; error: string | null; created_at: string | null; completed_at: string | null; expires_at: string | null }
export interface DeletionRequest { id: string; status: 'scheduled' | 'purging' | 'canceled' | 'completed'; scheduled_for: string; created_at: string | null; canceled_at: string | null }
export interface DeletionState { request: DeletionRequest | null; legal_hold: boolean; waiting_days: number }
/** A six-digit authenticator code or a recovery code. */
export type MfaProof = { code: string } | { recovery_code: string };

export interface BillingSource {
  subscription(signal?: AbortSignal): Promise<Subscription>;
  plans(signal?: AbortSignal): Promise<Plan[]>;
  invoices(page: number, signal?: AbortSignal): Promise<Page<Invoice>>;
  checkout(plan: string, interval: Interval): Promise<string>;
  change(plan: string, interval: Interval, version: number): Promise<Subscription>;
  cancel(): Promise<Subscription>;
  resume(): Promise<Subscription>;
  portal(): Promise<string>;
  supportGrants(signal?: AbortSignal): Promise<SupportGrant[]>;
  grantSupport(input: { hours: number; reason: string; include_payroll: boolean }): Promise<SupportGrant>;
  revokeSupport(id: string): Promise<SupportGrant>;
  exports(signal?: AbortSignal): Promise<DataExport[]>;
  requestExport(proof: MfaProof): Promise<DataExport>;
  downloadExport(id: string): Promise<void>;
  deletion(signal?: AbortSignal): Promise<DeletionState>;
  requestDeletion(confirmName: string, proof: MfaProof): Promise<DeletionRequest>;
  cancelDeletion(): Promise<DeletionRequest>;
  /** Leaves Wingora for a Stripe-hosted page (Checkout or the Customer Portal). */
  redirect(url: string): void;
}

const v1 = '/api/v1';
const data = async <T,>(p: Promise<{ data: T }>) => (await p).data;
async function post<T>(path: string, body: unknown = {}): Promise<T> {
  await ensureCsrfCookie();
  return data(apiRequest<{ data: T }>('POST', path, { body }));
}
/** Only Stripe's own https pages are followed. */
export function isStripeUrl(url: string): boolean {
  try {
    const u = new URL(url);
    return u.protocol === 'https:' && (u.hostname === 'stripe.com' || u.hostname.endsWith('.stripe.com'));
  } catch {
    return false;
  }
}

export const httpBillingSource: BillingSource = {
  subscription: (signal) => data(apiRequest('GET', `${v1}/billing/subscription`, { signal })),
  plans: (signal) => data(apiRequest('GET', `${v1}/billing/plans`, { signal })),
  invoices: (page, signal) => fromPaginator(apiRequest('GET', `${v1}/billing/invoices`, { query: { page: String(page) }, signal })),
  checkout: async (plan, interval) => (await post<{ url: string }>(`${v1}/billing/checkout`, { plan, interval })).url,
  change: (plan, interval, version) => post(`${v1}/billing/change`, { plan, interval, version }),
  cancel: () => post(`${v1}/billing/cancel`),
  resume: () => post(`${v1}/billing/resume`),
  portal: async () => (await post<{ url: string }>(`${v1}/billing/portal`)).url,
  supportGrants: (signal) => data(apiRequest('GET', `${v1}/account/support-grants`, { signal })),
  grantSupport: (input) => post(`${v1}/account/support-grants`, input),
  revokeSupport: (id) => post(`${v1}/account/support-grants/${id}/revoke`),
  exports: (signal) => data(apiRequest('GET', `${v1}/account/exports`, { signal })),
  requestExport: (proof) => post(`${v1}/account/exports`, proof),
  downloadExport: (id) => apiDownload(`${v1}/account/exports/${id}/download`, {}, 'wingora-export.zip'),
  deletion: (signal) => data(apiRequest('GET', `${v1}/account/deletion`, { signal })),
  requestDeletion: (confirmName, proof) => post(`${v1}/account/deletion`, { confirm_name: confirmName, ...proof }),
  cancelDeletion: () => post(`${v1}/account/deletion/cancel`),
  redirect: (url) => {
    if (isStripeUrl(url)) window.location.assign(url);
  },
};

const notConnected = () => Promise.reject(new ApiNotConnectedError());
export const disconnectedBillingSource: BillingSource = {
  ...(Object.fromEntries(Object.keys(httpBillingSource).map((key) => [key, notConnected])) as unknown as BillingSource),
  redirect: () => undefined,
};
const BillingSourceContext = createContext<BillingSource>(disconnectedBillingSource);
export const BillingSourceProvider = BillingSourceContext.Provider;
export const useBillingSource = () => useContext(BillingSourceContext);
