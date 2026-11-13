import { createContext, useContext } from 'react';
import { ApiNotConnectedError } from './dashboard';
import { apiRequest, ensureCsrfCookie } from './http';
import type { Page } from './employees';

/** Mirrors the Performance API (docs/architecture/performance.md). */
export type ObjectiveKind = 'objective' | 'okr';
export type ObjectiveStatus = 'draft' | 'active' | 'completed' | 'cancelled';
export type CycleStatus = 'draft' | 'open' | 'closed';
export type ReviewStatus = 'pending_self' | 'pending_manager' | 'completed' | 'acknowledged';
export type Relationship = 'peer' | 'direct_report' | 'manager' | 'other';
export type FeedbackStatus = 'requested' | 'submitted' | 'declined';
export type MeetingStatus = 'scheduled' | 'completed' | 'cancelled';
export type Scope = 'mine' | 'team' | 'all';
export interface Person { id: string; name: string; employee_number: string }

export interface KeyResult { id: string; title: string; start_value: string; target_value: string; current_value: string; unit: string | null; weight: number; progress: number }
export interface CheckIn { id: string; key_result: string | null; value: string | null; progress: number; note: string | null; author: string | null; created_at: string | null }
export interface Objective {
  id: string; title: string; description: string | null; kind: ObjectiveKind; status: ObjectiveStatus; progress: number; starts_on: string | null; due_on: string | null;
  completed_at: string | null; employee: Person | null; parent: { id: string; title: string } | null; cycle: { id: string; name: string } | null;
  key_result_count: number; version: number; can_edit: boolean; updated_at: string | null; key_results?: KeyResult[]; check_ins?: CheckIn[];
}
export interface KeyResultInput { id?: string; title: string; start_value?: number; target_value: number; unit?: string | null; weight?: number }
export interface ObjectiveInput {
  kind?: ObjectiveKind; employee_id?: string; title?: string; description?: string | null; status?: ObjectiveStatus; progress?: number;
  starts_on?: string | null; due_on?: string | null; parent_id?: string | null; cycle_id?: string | null; key_results?: KeyResultInput[]; version?: number;
}
export interface Question { key: string; label: string; type: 'text' | 'rating' }
export interface Cycle {
  id: string; name: string; status: CycleStatus; starts_on: string; ends_on: string; self_due_on: string | null; manager_due_on: string | null;
  rating_scale: number; questions: Question[]; review_count: number | null; completed_count: number | null; version: number;
}
export interface CycleInput { name: string; starts_on: string; ends_on: string; self_due_on: string | null; manager_due_on: string | null; rating_scale: number; questions: Question[]; version?: number }
export type Answers = Record<string, string | number>;
export interface FeedbackEntry { id: string; relationship: Relationship; anonymous: boolean; status: FeedbackStatus; reviewer: string | null; answers: FeedbackAnswers | null; submitted_at: string | null }
export interface FeedbackAnswers { strengths: string; improvements: string; rating: number | null }
export interface Review {
  id: string; status: ReviewStatus; cycle: { id: string; name: string; status: CycleStatus; self_due_on: string | null; manager_due_on: string | null; rating_scale: number };
  employee: Person | null; manager: Person | null; rating: number | null; self_submitted_at: string | null; manager_submitted_at: string | null; acknowledged_at: string | null;
  feedback_requested: number | null; feedback_submitted: number | null; can_self: boolean; can_manage: boolean; version: number;
  questions?: Question[]; self_answers?: Answers | null; manager_answers?: Answers | null; feedback?: FeedbackEntry[];
}
export interface FeedbackRequest {
  id: string; status: FeedbackStatus; relationship: Relationship; anonymous: boolean; subject: Person | null;
  cycle: { id: string; name: string; status: CycleStatus; manager_due_on: string | null; rating_scale: number }; answers: FeedbackAnswers | null; submitted_at: string | null;
}
export interface ActionItem { text: string; owner: 'employee' | 'manager'; done: boolean }
export interface Meeting {
  id: string; status: MeetingStatus; scheduled_at: string; duration_minutes: number; employee: Person | null; manager: Person | null; open_actions: number;
  is_manager: boolean; version: number; agenda?: string | null; shared_notes?: string | null; private_notes?: string | null; action_items?: ActionItem[];
}
export interface MeetingInput {
  employee_id?: string; scheduled_at?: string; duration_minutes?: number; agenda?: string | null; shared_notes?: string | null; private_notes?: string | null;
  status?: MeetingStatus; action_items?: ActionItem[]; version?: number;
}
export interface History {
  employee: Person;
  reviews: { id: string; cycle: { id: string; name: string; starts_on: string; ends_on: string; rating_scale: number }; status: ReviewStatus; rating: number | null; manager: Person | null; completed_at: string | null; feedback_count: number }[];
  objectives: { id: string; title: string; kind: ObjectiveKind; status: ObjectiveStatus; progress: number; due_on: string | null; cycle: string | null }[];
  summary: { objectives_completed: number; objectives_active: number; meetings_completed: number; feedback_received: number };
}
export interface ListQuery { page: number; scope: Scope; status?: string; q?: string; cycle_id?: string }

export interface PerformanceSource {
  objectives(q: ListQuery, signal?: AbortSignal): Promise<Page<Objective>>;
  objective(id: string, signal?: AbortSignal): Promise<Objective>;
  saveObjective(input: ObjectiveInput, id?: string): Promise<Objective>;
  checkIn(id: string, input: { key_result_id?: string; value?: number; progress?: number; note?: string | null }): Promise<Objective>;
  cycles(page: number, signal?: AbortSignal): Promise<Page<Cycle>>;
  saveCycle(input: CycleInput, id?: string): Promise<Cycle>;
  launchCycle(id: string, version: number): Promise<Cycle>;
  closeCycle(id: string, version: number): Promise<Cycle>;
  reviews(q: ListQuery, signal?: AbortSignal): Promise<Page<Review>>;
  review(id: string, signal?: AbortSignal): Promise<Review>;
  submitSelf(id: string, version: number, answers: Answers): Promise<Review>;
  submitManager(id: string, version: number, answers: Answers, rating: number): Promise<Review>;
  acknowledge(id: string, version: number): Promise<Review>;
  requestFeedback(id: string, input: { reviewer_id: string; relationship: Relationship; anonymous: boolean }): Promise<Review>;
  people(q: string, signal?: AbortSignal): Promise<Person[]>;
  feedback(page: number, signal?: AbortSignal): Promise<Page<FeedbackRequest>>;
  submitFeedback(id: string, input: { strengths: string; improvements: string; rating: number | null }): Promise<FeedbackRequest>;
  declineFeedback(id: string): Promise<FeedbackRequest>;
  meetings(q: ListQuery, signal?: AbortSignal): Promise<Page<Meeting>>;
  meeting(id: string, signal?: AbortSignal): Promise<Meeting>;
  saveMeeting(input: MeetingInput, id?: string): Promise<Meeting>;
  history(employeeId: string | null, signal?: AbortSignal): Promise<History>;
}

const v1 = '/api/v1/performance';
const data = async <T,>(p: Promise<{ data: T }>) => (await p).data;
async function write<T>(method: 'POST' | 'PATCH', path: string, body: unknown = {}): Promise<T> {
  await ensureCsrfCookie();
  return data(apiRequest<{ data: T }>(method, path, { body }));
}
const list = (q: ListQuery) => ({ page: String(q.page), scope: q.scope, status: q.status || undefined, q: q.q || undefined, cycle_id: q.cycle_id || undefined });

export const httpPerformanceSource: PerformanceSource = {
  objectives: (q, signal) => apiRequest('GET', `${v1}/objectives`, { query: list(q), signal }),
  objective: (id, signal) => data(apiRequest('GET', `${v1}/objectives/${id}`, { signal })),
  saveObjective: (input, id) => (id ? write('PATCH', `${v1}/objectives/${id}`, input) : write('POST', `${v1}/objectives`, input)),
  checkIn: (id, input) => write('POST', `${v1}/objectives/${id}/check-ins`, input),
  cycles: (page, signal) => apiRequest('GET', `${v1}/cycles`, { query: { page: String(page) }, signal }),
  saveCycle: (input, id) => (id ? write('PATCH', `${v1}/cycles/${id}`, input) : write('POST', `${v1}/cycles`, input)),
  launchCycle: (id, version) => write('POST', `${v1}/cycles/${id}/launch`, { version }),
  closeCycle: (id, version) => write('POST', `${v1}/cycles/${id}/close`, { version }),
  reviews: (q, signal) => apiRequest('GET', `${v1}/reviews`, { query: list(q), signal }),
  review: (id, signal) => data(apiRequest('GET', `${v1}/reviews/${id}`, { signal })),
  submitSelf: (id, version, answers) => write('POST', `${v1}/reviews/${id}/self`, { version, answers }),
  submitManager: (id, version, answers, rating) => write('POST', `${v1}/reviews/${id}/manager`, { version, answers, rating }),
  acknowledge: (id, version) => write('POST', `${v1}/reviews/${id}/acknowledge`, { version }),
  requestFeedback: (id, input) => write('POST', `${v1}/reviews/${id}/feedback`, input),
  people: (q, signal) => data(apiRequest('GET', `${v1}/people`, { query: { q: q || undefined }, signal })),
  feedback: (page, signal) => apiRequest('GET', `${v1}/feedback`, { query: { page: String(page) }, signal }),
  submitFeedback: (id, input) => write('POST', `${v1}/feedback/${id}/submit`, input),
  declineFeedback: (id) => write('POST', `${v1}/feedback/${id}/decline`),
  meetings: (q, signal) => apiRequest('GET', `${v1}/meetings`, { query: list(q), signal }),
  meeting: (id, signal) => data(apiRequest('GET', `${v1}/meetings/${id}`, { signal })),
  saveMeeting: (input, id) => (id ? write('PATCH', `${v1}/meetings/${id}`, input) : write('POST', `${v1}/meetings`, input)),
  history: (employeeId, signal) => data(apiRequest('GET', employeeId ? `${v1}/employees/${employeeId}/history` : `${v1}/history`, { signal })),
};

const notConnected = () => Promise.reject(new ApiNotConnectedError());
export const disconnectedPerformanceSource: PerformanceSource = Object.fromEntries(
  Object.keys(httpPerformanceSource).map((key) => [key, notConnected]),
) as unknown as PerformanceSource;
const PerformanceSourceContext = createContext<PerformanceSource>(disconnectedPerformanceSource);
export const PerformanceSourceProvider = PerformanceSourceContext.Provider;
export const usePerformanceSource = () => useContext(PerformanceSourceContext);
