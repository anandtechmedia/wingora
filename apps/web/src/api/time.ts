import { createContext, useContext } from 'react';
import { ApiNotConnectedError } from './dashboard';
import { apiDownload, apiRequest, ensureCsrfCookie } from './http';
import type { Page } from './employees';

/** Mirrors the Attendance and Leave API (docs/architecture/attendance-leave.md). Local times are `YYYY-MM-DD HH:mm` in the employee's zone. */
export interface PersonRef { id: string; name: string; employee_number: string }
export type RequestStatus = 'pending' | 'approved' | 'rejected' | 'cancelled';
export type RequestScope = 'mine' | 'approvals' | 'all';

export interface AttendanceRecord {
  id: string; employee: PersonRef; work_date: string; timezone: string; clock_in_at: string; clock_out_at: string | null;
  clock_in_local: string; clock_out_local: string | null; shift: { name: string; start_local: string; end_local: string } | null;
  worked_minutes: number | null; late_minutes: number; early_minutes: number; overtime_minutes: number;
  source: 'clock' | 'manual' | 'request'; note: string | null; open: boolean;
}
export interface AttendanceToday {
  employee: PersonRef; timezone: string; today: string; can_clock: boolean;
  shift: { name: string; start_time: string; end_time: string } | null; record: AttendanceRecord | null;
}
export interface AttendanceRequestItem {
  id: string; employee: PersonRef; work_date: string; timezone: string; clock_in_local: string; clock_out_local: string | null;
  reason: string; status: RequestStatus; decided_by: string | null; decided_at: string | null; decision_note: string | null;
  created_at: string | null; can_decide: boolean; can_cancel: boolean;
}
export interface CorrectionInput { work_date: string; clock_in: string; clock_out: string | null; reason: string }
export interface ReportRow {
  employee: PersonRef; department: string | null; days_present: number; days_absent: number; late_count: number; late_minutes: number;
  early_count: number; early_minutes: number; worked_minutes: number; overtime_minutes: number;
}
export interface RangeQuery { from: string; to: string; page?: number; search?: string; employee?: string }

export interface Shift {
  id: string; name: string; code: string; start_time: string; end_time: string; overnight: boolean; break_minutes: number;
  late_grace_minutes: number; early_grace_minutes: number; overtime_after_minutes: number | null; status: 'active' | 'inactive';
}
export type ShiftInput = Partial<Omit<Shift, 'id' | 'overnight'>>;
export interface WorkSchedule { id: string; name: string; is_default: boolean; status: 'active' | 'inactive'; days: Record<string, { id: string; name: string } | null> }
export interface WorkScheduleInput { name?: string; is_default?: boolean; status?: 'active' | 'inactive'; days?: Record<string, string | null> }

export interface LeaveType {
  id: string; name: string; code: string; country_code: string | null; rule_key: string | null; is_paid: boolean;
  allow_half_day: boolean; requires_document: boolean; approval_steps: 1 | 2; status: 'active' | 'inactive';
}
export type LeaveTypeInput = Partial<Omit<LeaveType, 'id'>>;
export interface LeavePolicy {
  id: string; legal_entity: { id: string; name: string } | null; effective_from: string; effective_to: string | null;
  annual_entitlement: number; accrual: 'annual' | 'monthly' | 'none'; prorate_new_joiners: boolean; carry_forward_max: number;
  carry_forward_expiry_months: number | null; allow_negative: boolean; max_consecutive_days: number | null; min_notice_days: number;
}
export type LeavePolicyInput = Partial<Omit<LeavePolicy, 'id' | 'legal_entity'>> & { legal_entity_id?: string | null };
export interface LeaveBalance {
  leave_type: { id: string; name: string; code: string; is_paid: boolean }; year: number;
  entitled: number; carried_forward: number; adjustments: number; used: number; lapsed: number; carried_out: number;
  expired: number; pending: number; available: number;
}
export interface LeaveRequestItem {
  id: string; employee: PersonRef; leave_type: { id: string; name: string; code: string; is_paid: boolean };
  start_date: string; end_date: string; half_day: 'first_half' | 'second_half' | null; days: number; reason: string | null;
  status: RequestStatus; current_step: number; approval_steps: number; cancellation_reason: string | null; created_at: string | null;
  approvals: { step: number; decision: 'approved' | 'rejected'; by: string | null; note: string | null; at: string }[];
  can_decide: boolean; can_cancel: boolean;
}
export interface LeaveInput { leave_type: string; start_date: string; end_date: string; half_day: string | null; reason: string | null }
export interface LedgerEntry { id: string; leave_type: { id: string; name: string }; year: number; entry_type: string; amount: number; effective_on: string; expires_on: string | null; note: string | null; author: string | null }
export interface HolidayItem { date: string; name: string; type?: string; paid?: boolean }

export interface TimeSource {
  today(signal?: AbortSignal): Promise<AttendanceToday>;
  clockIn(note?: string): Promise<AttendanceRecord>;
  clockOut(): Promise<AttendanceRecord>;
  records(q: RangeQuery, signal?: AbortSignal): Promise<Page<AttendanceRecord>>;
  attendanceRequests(scope: RequestScope, page: number, signal?: AbortSignal): Promise<Page<AttendanceRequestItem>>;
  submitCorrection(input: CorrectionInput): Promise<AttendanceRequestItem>;
  decideAttendance(id: string, approve: boolean, note: string | null): Promise<AttendanceRequestItem>;
  cancelAttendance(id: string): Promise<AttendanceRequestItem>;
  report(q: RangeQuery, signal?: AbortSignal): Promise<Page<ReportRow>>;
  exportReport(q: RangeQuery): Promise<void>;
  shifts(signal?: AbortSignal): Promise<Shift[]>;
  saveShift(input: ShiftInput, id?: string): Promise<Shift>;
  schedules(signal?: AbortSignal): Promise<WorkSchedule[]>;
  saveSchedule(input: WorkScheduleInput, id?: string): Promise<WorkSchedule>;

  leaveTypes(signal?: AbortSignal): Promise<LeaveType[]>;
  saveLeaveType(input: LeaveTypeInput, id?: string): Promise<LeaveType>;
  policies(typeId: string, signal?: AbortSignal): Promise<LeavePolicy[]>;
  savePolicy(typeId: string, input: LeavePolicyInput): Promise<LeavePolicy>;
  allocate(typeId: string, year: number): Promise<{ employees: number; entries_created: number }>;
  carryForward(typeId: string, fromYear: number): Promise<{ employees: number }>;
  balances(year: number, signal?: AbortSignal): Promise<LeaveBalance[]>;
  ledger(year: number, signal?: AbortSignal): Promise<Page<LedgerEntry>>;
  leaveRequests(scope: RequestScope, page: number, signal?: AbortSignal): Promise<Page<LeaveRequestItem>>;
  previewLeave(start: string, end: string, halfDay: string | null): Promise<number>;
  applyLeave(input: LeaveInput): Promise<LeaveRequestItem>;
  decideLeave(id: string, approve: boolean, note: string | null): Promise<LeaveRequestItem>;
  cancelLeave(id: string, reason: string | null): Promise<LeaveRequestItem>;
  holidays(year: number, signal?: AbortSignal): Promise<{ data: HolidayItem[]; meta: { work_location: string | null; timezone: string } }>;
}

const v1 = '/api/v1';
const data = async <T>(p: Promise<{ data: T }>) => (await p).data;
async function write<T>(method: 'POST' | 'PUT' | 'PATCH', path: string, body?: unknown): Promise<T> {
  await ensureCsrfCookie();
  return data(apiRequest<{ data: T }>(method, path, { body: body ?? {} }));
}
const range = (q: RangeQuery) => ({ from: q.from, to: q.to, page: q.page ? String(q.page) : undefined, search: q.search, employee: q.employee });

export const httpTimeSource: TimeSource = {
  today: (signal) => data(apiRequest('GET', `${v1}/attendance/me`, { signal })),
  clockIn: (note) => write('POST', `${v1}/attendance/clock-in`, note ? { note } : {}),
  clockOut: () => write('POST', `${v1}/attendance/clock-out`),
  records: (q, signal) => apiRequest('GET', `${v1}/attendance/records`, { query: range(q), signal }),
  attendanceRequests: (scope, page, signal) => apiRequest('GET', `${v1}/attendance/requests`, { query: { scope, page: String(page) }, signal }),
  submitCorrection: (input) => write('POST', `${v1}/attendance/requests`, input),
  decideAttendance: (id, approve, note) => write('POST', `${v1}/attendance/requests/${id}/${approve ? 'approve' : 'reject'}`, { note }),
  cancelAttendance: (id) => write('POST', `${v1}/attendance/requests/${id}/cancel`),
  report: (q, signal) => apiRequest('GET', `${v1}/attendance/report`, { query: range(q), signal }),
  exportReport: (q) => apiDownload(`${v1}/attendance/report`, { ...range(q), page: undefined, format: 'csv' }, `attendance-${q.from}-${q.to}.csv`),
  shifts: (signal) => data(apiRequest('GET', `${v1}/shifts`, { signal })),
  saveShift: (input, id) => (id ? write('PATCH', `${v1}/shifts/${id}`, input) : write('POST', `${v1}/shifts`, input)),
  schedules: (signal) => data(apiRequest('GET', `${v1}/work-schedules`, { signal })),
  saveSchedule: (input, id) => (id ? write('PATCH', `${v1}/work-schedules/${id}`, input) : write('POST', `${v1}/work-schedules`, input)),

  leaveTypes: (signal) => data(apiRequest('GET', `${v1}/leave-types`, { signal })),
  saveLeaveType: (input, id) => (id ? write('PATCH', `${v1}/leave-types/${id}`, input) : write('POST', `${v1}/leave-types`, input)),
  policies: (typeId, signal) => data(apiRequest('GET', `${v1}/leave-types/${typeId}/policies`, { signal })),
  savePolicy: (typeId, input) => write('POST', `${v1}/leave-types/${typeId}/policies`, input),
  allocate: (typeId, year) => write('POST', `${v1}/leave-types/${typeId}/allocate`, { year }),
  carryForward: (typeId, fromYear) => write('POST', `${v1}/leave-types/${typeId}/carry-forward`, { from_year: fromYear }),
  balances: (year, signal) => data(apiRequest('GET', `${v1}/leave/balances`, { query: { year: String(year) }, signal })),
  ledger: (year, signal) => apiRequest('GET', `${v1}/leave/ledger`, { query: { year: String(year), per_page: '100' }, signal }),
  leaveRequests: (scope, page, signal) => apiRequest('GET', `${v1}/leave/requests`, { query: { scope, page: String(page) }, signal }),
  previewLeave: async (start, end, halfDay) => (await write<{ days: number }>('POST', `${v1}/leave/requests/preview`, { start_date: start, end_date: end, half_day: halfDay })).days,
  applyLeave: (input) => write('POST', `${v1}/leave/requests`, input),
  decideLeave: (id, approve, note) => write('POST', `${v1}/leave/requests/${id}/${approve ? 'approve' : 'reject'}`, { note }),
  cancelLeave: (id, reason) => write('POST', `${v1}/leave/requests/${id}/cancel`, { reason }),
  holidays: (year, signal) => apiRequest('GET', `${v1}/leave/holidays`, { query: { year: String(year) }, signal }),
};

const notConnected = () => Promise.reject(new ApiNotConnectedError());
export const disconnectedTimeSource: TimeSource = Object.fromEntries(
  Object.keys(httpTimeSource).map((key) => [key, notConnected]),
) as unknown as TimeSource;

const TimeSourceContext = createContext<TimeSource>(disconnectedTimeSource);
export const TimeSourceProvider = TimeSourceContext.Provider;
export const useTimeSource = () => useContext(TimeSourceContext);
