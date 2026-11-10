import { createContext, useContext } from 'react';
import { ApiNotConnectedError } from './dashboard';
import { apiDownload, apiRequest, ensureCsrfCookie } from './http';
import type { Page } from './employees';

/** Mirrors the Payroll Preparation API (docs/architecture/payroll.md). Money is exact: decimal string + minor units + currency. */
export interface Money { amount: string; minor: number; currency: string }
export interface PersonRef { id: string; name: string; employee_number: string }
export type LineSource = 'calculated' | 'manual' | 'provider';
export type RunStatus = 'draft' | 'in_review' | 'approved' | 'published';
export type RunType = 'regular' | 'off_cycle';
export type PayFrequency = 'weekly' | 'biweekly' | 'semimonthly' | 'monthly';

export interface PayrollRun {
  id: string; type: RunType; status: RunStatus; currency: string;
  legal_entity: { id: string; name: string; country_code: string };
  period: { id: string; start_date: string; end_date: string; pay_date: string; pay_frequency: PayFrequency; calendar: string };
  approval_levels: number; current_level: number; review_cycle: number; employee_count: number;
  gross: Money; deductions: Money; net: Money; notes: string | null; rejection_note: string | null;
  prepared_at: string | null; submitted_at: string | null; approved_at: string | null; published_at: string | null; disclaimer: string;
  can?: { edit: boolean; submit: boolean; approve: boolean; publish: boolean; export: boolean };
}
export interface PayrollRunDetail extends PayrollRun {
  prepared_by: string | null; submitted_by: string | null; payslips_with_issues: number;
  totals_by_source: { source: LineSource; kind: 'earning' | 'deduction'; lines: number; total: Money }[];
  approvals: { review_cycle: number; level: number; decision: 'approved' | 'rejected'; by: string | null; note: string | null; decided_at: string }[];
}
export interface PayrollLine {
  id: string; code: string; name: string; kind: 'earning' | 'deduction'; source: LineSource; amount: Money;
  basis: Record<string, unknown> | null; reason: string | null; provider: string | null; provider_reference: string | null;
}
export interface PayslipIssue { code: string; message: string; blocking?: boolean }
export interface Payslip {
  id: string; employee: { employee_number?: string; name?: string; job_title?: string | null; department?: string | null; legal_entity?: string | null };
  currency: string; days_paid: number; period_days: number; gross: Money; deductions: Money; net: Money; issues: PayslipIssue[]; lines?: PayrollLine[];
}
export interface MyPayslip extends Payslip { period: { start_date: string; end_date: string; pay_date: string }; run_type: RunType }
export interface LineInput { code: string; name: string; kind: 'earning' | 'deduction'; amount: string; reason: string }

export interface PayrollCalendar {
  id: string; name: string; pay_frequency: PayFrequency; first_period_start: string; pay_date_offset_days: number; approval_levels: number;
  provider: string; status: 'active' | 'inactive'; legal_entity: { id: string; name: string; country_code: string; currency_code: string };
}
export interface CalendarInput { legal_entity?: string; name: string; pay_frequency?: PayFrequency; first_period_start?: string; pay_date_offset_days: number; approval_levels: number }
export interface PayrollPeriod {
  id: string; start_date: string; end_date: string; pay_date: string;
  calendar?: { id: string; name: string; pay_frequency: PayFrequency; legal_entity: string; currency: string }; runs?: { id: string; type: RunType; status: RunStatus }[];
}
export type ComponentMethod = 'fixed' | 'percent_of_base' | 'manual' | 'provider';
export interface SalaryComponent {
  id: string; code: string; name: string; kind: 'earning' | 'deduction'; method: ComponentMethod; source: LineSource;
  amount: Money | null; rate_percent: string | null; rule_key: string | null; effective_from: string; effective_to: string | null; sort_order: number;
}
export interface SalaryStructure { id: string; name: string; code: string; status: 'active' | 'inactive'; legal_entity: { id: string; name: string; currency_code: string }; components: SalaryComponent[] }
export interface ComponentInput { code: string; name: string; kind: 'earning' | 'deduction'; method: ComponentMethod; amount?: string | null; rate_percent?: string | null; rule_key?: string | null; effective_from: string }

export interface CompensationRecord {
  id: string; amount: Money; basis: 'annual' | 'monthly' | 'period'; pay_frequency: PayFrequency; effective_from: string; effective_to: string | null;
  reason: string; source: 'manual'; salary_structure: { id: string; name: string; code: string } | null; created_by: string | null; created_at: string | null;
}
export interface CompensationRow { employee: PersonRef; legal_entity: string | null; current: CompensationRecord | null }
export interface CompensationInput { amount: string; basis: CompensationRecord['basis']; pay_frequency: PayFrequency; effective_from: string; salary_structure: string | null; reason: string }

export interface Loan {
  id: string; employee: PersonRef; currency: string; principal: Money; installment: Money; repaid: Money; outstanding: Money;
  first_deduction_on: string; status: 'active' | 'closed' | 'cancelled'; reason: string;
}
export interface LoanInput { employee: string; principal: string; installment: string; first_deduction_on: string; reason: string }
export type ReimbursementStatus = 'pending' | 'approved' | 'rejected' | 'cancelled' | 'paid';
export interface Reimbursement {
  id: string; employee: PersonRef; amount: Money; category: string; description: string; expense_date: string; status: ReimbursementStatus;
  decision_note: string | null; decided_at: string | null; has_receipt: boolean; can_decide: boolean; can_cancel: boolean;
}
export interface ReimbursementInput { amount: string; category: string; description: string; expense_date: string; receipt: File | null }
export type Scope = 'mine' | 'approvals' | 'all';

export interface PayrollSource {
  runs(q: { status?: string; page: number }, signal?: AbortSignal): Promise<Page<PayrollRun>>;
  run(id: string, signal?: AbortSignal): Promise<PayrollRunDetail>;
  createRun(period: string, type: RunType): Promise<PayrollRun>;
  payslips(runId: string, q: { search?: string; issues?: boolean; page: number }, signal?: AbortSignal): Promise<Page<Payslip>>;
  prepare(runId: string): Promise<PayrollRun>;
  addLine(runId: string, payslipId: string, input: LineInput): Promise<PayrollLine>;
  removeLine(runId: string, lineId: string): Promise<void>;
  importProvider(runId: string, file: File): Promise<{ lines: number }>;
  submit(runId: string): Promise<PayrollRun>;
  decide(runId: string, approve: boolean, note: string | null): Promise<PayrollRun>;
  publish(runId: string): Promise<PayrollRun>;
  exportRun(runId: string, format: 'summary' | 'register' | 'provider'): Promise<void>;
  periods(signal?: AbortSignal): Promise<Page<PayrollPeriod>>;
  calendars(signal?: AbortSignal): Promise<PayrollCalendar[]>;
  saveCalendar(input: CalendarInput, id?: string): Promise<PayrollCalendar>;
  generatePeriods(calendarId: string, count: number): Promise<PayrollPeriod[]>;
  structures(signal?: AbortSignal): Promise<SalaryStructure[]>;
  createStructure(input: { legal_entity: string; name: string; code: string }): Promise<SalaryStructure>;
  addComponent(structureId: string, input: ComponentInput): Promise<SalaryComponent>;
  endComponent(structureId: string, componentId: string, effectiveTo: string): Promise<SalaryComponent>;
  legalEntities(signal?: AbortSignal): Promise<{ id: string; name: string; country_code: string }[]>;
  compensation(q: { search?: string; page: number }, signal?: AbortSignal): Promise<Page<CompensationRow>>;
  history(employeeId: string, signal?: AbortSignal): Promise<CompensationRecord[]>;
  addCompensation(employeeId: string, input: CompensationInput): Promise<CompensationRecord>;
  loans(scope: 'mine' | 'all', page: number, signal?: AbortSignal): Promise<Page<Loan>>;
  createLoan(input: LoanInput): Promise<Loan>;
  cancelLoan(id: string): Promise<Loan>;
  reimbursements(scope: Scope, page: number, signal?: AbortSignal): Promise<Page<Reimbursement>>;
  submitReimbursement(input: ReimbursementInput): Promise<Reimbursement>;
  decideReimbursement(id: string, approve: boolean, note: string | null): Promise<Reimbursement>;
  cancelReimbursement(id: string): Promise<Reimbursement>;
  downloadReceipt(id: string): Promise<void>;
  myPayslips(page: number, signal?: AbortSignal): Promise<Page<MyPayslip>>;
  downloadPayslip(id: string): Promise<void>;
}

const v1 = '/api/v1';
const data = async <T>(p: Promise<{ data: T }>) => (await p).data;
async function write<T>(method: 'POST' | 'PATCH' | 'DELETE', path: string, body?: unknown): Promise<T> {
  await ensureCsrfCookie();
  return data(apiRequest<{ data: T }>(method, path, { body: method === 'DELETE' ? undefined : body ?? {} }));
}
const pageQuery = (page: number, extra: Record<string, string | undefined> = {}) => ({ page: String(page), ...extra });

export const httpPayrollSource: PayrollSource = {
  runs: (q, signal) => apiRequest('GET', `${v1}/payroll/runs`, { query: pageQuery(q.page, { status: q.status || undefined }), signal }),
  run: (id, signal) => data(apiRequest('GET', `${v1}/payroll/runs/${id}`, { signal })),
  createRun: (period, type) => write('POST', `${v1}/payroll/runs`, { period, type }),
  payslips: (runId, q, signal) => apiRequest('GET', `${v1}/payroll/runs/${runId}/payslips`, { query: pageQuery(q.page, { search: q.search || undefined, issues: q.issues ? '1' : undefined }), signal }),
  prepare: (runId) => write('POST', `${v1}/payroll/runs/${runId}/prepare`),
  addLine: (runId, payslipId, input) => write('POST', `${v1}/payroll/runs/${runId}/payslips/${payslipId}/lines`, input),
  removeLine: async (runId, lineId) => { await ensureCsrfCookie(); await apiRequest('DELETE', `${v1}/payroll/runs/${runId}/lines/${lineId}`); },
  importProvider: async (runId, file) => {
    const form = new FormData();
    form.append('file', file);
    return write('POST', `${v1}/payroll/runs/${runId}/provider-import`, form);
  },
  submit: (runId) => write('POST', `${v1}/payroll/runs/${runId}/submit`),
  decide: (runId, approve, note) => write('POST', `${v1}/payroll/runs/${runId}/${approve ? 'approve' : 'reject'}`, { note }),
  publish: (runId) => write('POST', `${v1}/payroll/runs/${runId}/publish`),
  exportRun: (runId, format) => apiDownload(`${v1}/payroll/runs/${runId}/export`, { format }, `payroll-${format}.csv`),
  periods: (signal) => apiRequest('GET', `${v1}/payroll/periods`, { query: { per_page: '100' }, signal }),
  calendars: (signal) => data(apiRequest('GET', `${v1}/payroll/calendars`, { signal })),
  saveCalendar: (input, id) => (id ? write('PATCH', `${v1}/payroll/calendars/${id}`, input) : write('POST', `${v1}/payroll/calendars`, input)),
  generatePeriods: (calendarId, count) => write('POST', `${v1}/payroll/calendars/${calendarId}/periods`, { count }),
  structures: (signal) => data(apiRequest('GET', `${v1}/payroll/structures`, { signal })),
  createStructure: (input) => write('POST', `${v1}/payroll/structures`, input),
  addComponent: (structureId, input) => write('POST', `${v1}/payroll/structures/${structureId}/components`, input),
  endComponent: (structureId, componentId, effectiveTo) => write('POST', `${v1}/payroll/structures/${structureId}/components/${componentId}/end`, { effective_to: effectiveTo }),
  legalEntities: (signal) => data(apiRequest('GET', `${v1}/legal-entities`, { signal })),
  compensation: (q, signal) => apiRequest('GET', `${v1}/payroll/compensation`, { query: pageQuery(q.page, { search: q.search || undefined }), signal }),
  history: (employeeId, signal) => data(apiRequest('GET', `${v1}/employees/${employeeId}/compensation-history`, { signal })),
  addCompensation: (employeeId, input) => write('POST', `${v1}/employees/${employeeId}/compensation-history`, input),
  loans: (scope, page, signal) => apiRequest('GET', `${v1}/payroll/loans`, { query: pageQuery(page, { scope }), signal }),
  createLoan: (input) => write('POST', `${v1}/payroll/loans`, input),
  cancelLoan: (id) => write('POST', `${v1}/payroll/loans/${id}/cancel`),
  reimbursements: (scope, page, signal) => apiRequest('GET', `${v1}/reimbursements`, { query: pageQuery(page, { scope }), signal }),
  submitReimbursement: async ({ receipt, ...input }) => {
    let receipt_id: string | undefined;
    if (receipt) {
      const form = new FormData();
      form.append('file', receipt);
      form.append('category', 'general');
      receipt_id = (await write<{ id: string }>('POST', `${v1}/files`, form)).id;
    }
    return write('POST', `${v1}/reimbursements`, { ...input, receipt_id });
  },
  decideReimbursement: (id, approve, note) => write('POST', `${v1}/reimbursements/${id}/${approve ? 'approve' : 'reject'}`, { note }),
  cancelReimbursement: (id) => write('POST', `${v1}/reimbursements/${id}/cancel`),
  downloadReceipt: (id) => apiDownload(`${v1}/reimbursements/${id}/receipt`, {}, 'receipt'),
  myPayslips: (page, signal) => apiRequest('GET', `${v1}/payroll/payslips/mine`, { query: pageQuery(page), signal }),
  downloadPayslip: (id) => apiDownload(`${v1}/payroll/payslips/${id}/pdf`, {}, 'payslip.pdf'),
};

const notConnected = () => Promise.reject(new ApiNotConnectedError());
export const disconnectedPayrollSource: PayrollSource = Object.fromEntries(
  Object.keys(httpPayrollSource).map((key) => [key, notConnected]),
) as unknown as PayrollSource;

const PayrollSourceContext = createContext<PayrollSource>(disconnectedPayrollSource);
export const PayrollSourceProvider = PayrollSourceContext.Provider;
export const usePayrollSource = () => useContext(PayrollSourceContext);
