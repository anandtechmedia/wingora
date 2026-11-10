import { createContext, useContext } from 'react';
import { ApiNotConnectedError } from './dashboard';
import { apiDownload, apiRequest, ensureCsrfCookie } from './http';
import type { Page } from './employees';

/** Mirrors the Recruitment and Onboarding API (docs/architecture/recruitment-onboarding.md). */
export type OpeningStatus = 'draft' | 'published' | 'closed';
export type Workplace = 'onsite' | 'hybrid' | 'remote';
export type ApplicationStatus = 'active' | 'rejected' | 'withdrawn' | 'hired';
export type StageKind = 'active' | 'hired' | 'rejected';
export type DocumentKind = 'resume' | 'cover_letter' | 'portfolio' | 'certificate' | 'offer_letter' | 'other';
export type InterviewMode = 'video' | 'phone' | 'onsite';
export type Recommendation = 'strong_yes' | 'yes' | 'no' | 'strong_no';
export type OfferStatus = 'draft' | 'approved' | 'sent' | 'accepted' | 'declined' | 'withdrawn';
export type OfferAction = 'approve' | 'send' | 'accept' | 'decline' | 'withdraw';
export type Frequency = 'annual' | 'monthly' | 'hourly';
export interface Person { id: string; name: string; employee_number?: string }

export interface Stage { id: string; key: string; name: string; kind: StageKind; sort_order: number }
export interface Opening {
  id: string; title: string; slug: string; status: OpeningStatus; workplace: Workplace; employment_type: string | null; openings: number;
  summary: string | null; description: string | null; requirements: string | null; closes_on: string | null; published_at: string | null; closed_at: string | null;
  legal_entity: { id: string; name: string; country_code: string; currency_code: string } | null; department: { id: string; name: string } | null;
  work_location: { id: string; name: string; city: string | null } | null; hiring_manager: Person | null; applications_count: number | null; active_count: number | null;
}
export interface OpeningInput {
  title: string; legal_entity_id?: string; department_id?: string | null; work_location_id?: string | null; hiring_manager_id?: string | null; employment_type?: string | null;
  workplace: Workplace; openings: number; summary: string | null; description: string; requirements: string | null; closes_on: string | null;
}
export interface Candidate {
  id: string; name: string; first_name: string; last_name: string; email: string | null; phone: string | null; location: string | null; linkedin_url: string | null;
  source: string; on_hold: boolean; anonymized: boolean;
}
export interface CandidateDocument { id: string; kind: DocumentKind; suggested_kind: DocumentKind | null; name: string | null; mime_type: string | null; size: number | null; uploaded_at: string | null }
export interface Application {
  id: string; status: ApplicationStatus; version: number; position: number; stage: Stage | null; opening: { id: string; title: string; status: OpeningStatus } | null;
  candidate: Candidate | null; applied_at: string | null; consent: { version: string | null; given_at: string | null }; retain_until: string | null;
  rejection_reason: string | null; cover_letter: string | null; employee: Person | null; latest_offer_status: OfferStatus | null; documents: CandidateDocument[] | null;
  can: { act: boolean; manage: boolean; offers: boolean };
}
export interface BoardColumn extends Stage { cards: Application[] }
export interface Feedback { id: string; author: string | null; rating: number; recommendation: Recommendation; strengths: string | null; concerns: string | null; notes: string | null; submitted_at: string | null }
export interface Interview {
  id: string; title: string; starts_at: string; ends_at: string; timezone: string; mode: InterviewMode; location: string | null; status: 'scheduled' | 'completed' | 'cancelled';
  application: { id: string; candidate: string | null; opening: string | null } | null; panel: { id: string; name: string; submitted: boolean }[];
  is_panelist: boolean; my_feedback: Feedback | null; feedback: Feedback[] | null;
}
export interface InterviewInput { application_id: string; title: string; starts_at: string; duration_minutes: number; timezone: string; mode: InterviewMode; location: string | null; panel: string[] }
export interface FeedbackInput { rating: number; recommendation: Recommendation; strengths: string | null; concerns: string | null; notes: string | null }
export interface Offer {
  id: string; status: OfferStatus; job_title: string; base_salary: { amount: string; currency: string }; pay_frequency: Frequency; start_date: string | null; expires_on: string | null;
  notes: string | null; letter: { name: string; size: number } | null; approved_by: string | null; approved_at: string | null; sent_at: string | null; responded_at: string | null; next: OfferStatus[];
}
export interface OfferInput { job_title: string; base_salary: string; pay_frequency: Frequency; start_date: string | null; expires_on: string | null; notes: string | null }
export interface HireInput { employee_number: string; work_email: string | null; template_id: string | null; version: number }
export interface AiDraft { id: string; feature: string; content: string; status: 'pending_review' | 'accepted' | 'discarded'; model: string | null; ai_generated: true; requires_review: boolean; reviewed_by: string | null; reviewed_at: string | null; created_at: string | null }
export interface RecruitmentSettings {
  careers_enabled: boolean; careers_slug: string | null; careers_headline: string | null; careers_intro: string | null; retention_days: number; consent_version: string;
  consent_text: string | null; ai_assist_enabled: boolean; ai_available: boolean; stages: Stage[];
}
export type SettingsInput = Partial<Pick<RecruitmentSettings, 'careers_enabled' | 'careers_slug' | 'careers_headline' | 'careers_intro' | 'retention_days' | 'consent_text' | 'ai_assist_enabled'>>;
export interface NewCandidateInput { opening_id: string; first_name: string; last_name: string; email: string; phone: string | null; source: string; resume: File | null; consent_confirmed: boolean }
export interface PanelUser { id: string; name: string }

export type Assignee = 'hr' | 'manager' | 'employee' | 'user';
export type OnboardingStage = 'pre_boarding' | 'first_day' | 'first_week' | 'first_month';
export type TaskStatus = 'pending' | 'submitted' | 'completed' | 'returned' | 'skipped';
export interface TemplateTask { id: string; stage: OnboardingStage; title: string; description: string | null; assignee: Assignee; assignee_user: PanelUser | null; due_offset_days: number; document_key: string | null; document_name: string | null }
export interface Template { id: string; name: string; description: string | null; status: 'active' | 'archived'; legal_entity: { id: string; name: string; country_code: string } | null; tasks: TemplateTask[] }
export interface TemplateTaskInput { stage: OnboardingStage; title: string; assignee: Assignee; due_offset_days: number; document_key: string | null; document_name: string | null; description?: string | null }
export interface DocumentRequirement { key: string; name: string; description: string | null; reference: string | null }
export interface Progress { total: number; done: number; overdue: number; percent: number }
export interface OnboardingTask {
  id: string; stage: OnboardingStage; title: string; description: string | null; assignee: Assignee; assignee_user: PanelUser | null; due_on: string; overdue: boolean; status: TaskStatus;
  review_note: string | null; document: { key: string; name: string; file: { name: string; size: number } | null } | null; completed_at: string | null;
  can: { work: boolean; review: boolean }; employee?: Person | null; plan_id?: string;
}
export interface Plan {
  id: string; status: 'in_progress' | 'completed' | 'cancelled'; start_date: string; completed_at: string | null; employee: Person & { job_title: string | null; department: string | null };
  manager: Person | null; template: { id: string; name: string } | null; progress: Progress; tasks: OnboardingTask[] | null; can: { manage: boolean };
}

export interface RecruitmentSource {
  settings(signal?: AbortSignal): Promise<RecruitmentSettings>;
  saveSettings(input: SettingsInput): Promise<RecruitmentSettings>;
  addStage(name: string): Promise<RecruitmentSettings>;
  renameStage(id: string, name: string): Promise<RecruitmentSettings>;
  removeStage(id: string): Promise<RecruitmentSettings>;
  openings(q: { status?: string; q?: string; page: number }, signal?: AbortSignal): Promise<Page<Opening>>;
  opening(id: string, signal?: AbortSignal): Promise<Opening>;
  saveOpening(input: OpeningInput, id?: string): Promise<Opening>;
  openingAction(id: string, action: 'publish' | 'close'): Promise<Opening>;
  board(q: { opening_id?: string; q?: string }, signal?: AbortSignal): Promise<BoardColumn[]>;
  application(id: string, signal?: AbortSignal): Promise<Application>;
  addCandidate(input: NewCandidateInput): Promise<Application>;
  move(id: string, stageId: string, version: number): Promise<Application>;
  reject(id: string, reason: string, version: number): Promise<Application>;
  hold(id: string, onHold: boolean): Promise<Application>;
  erase(id: string, reason: string): Promise<void>;
  uploadDocument(applicationId: string, file: File, kind: DocumentKind): Promise<CandidateDocument>;
  setDocumentKind(id: string, kind: DocumentKind): Promise<CandidateDocument>;
  downloadDocument(id: string): Promise<void>;
  interviews(q: { scope?: 'mine' | 'all'; application_id?: string; upcoming?: boolean }, signal?: AbortSignal): Promise<Interview[]>;
  schedule(input: InterviewInput): Promise<Interview>;
  interviewAction(id: string, action: 'cancel' | 'complete'): Promise<Interview>;
  submitFeedback(id: string, input: FeedbackInput): Promise<Interview>;
  panelUsers(search: string, signal?: AbortSignal): Promise<PanelUser[]>;
  offers(applicationId: string, signal?: AbortSignal): Promise<Offer[]>;
  createOffer(applicationId: string, input: OfferInput): Promise<Offer>;
  offerAction(id: string, action: OfferAction, note: string | null): Promise<Offer>;
  hire(applicationId: string, input: HireInput): Promise<{ application: Application; employee: Person; onboarding_plan_id: string | null }>;
  drafts(applicationId: string, signal?: AbortSignal): Promise<AiDraft[]>;
  summarize(applicationId: string): Promise<AiDraft>;
  organizeDocuments(applicationId: string): Promise<{ id: string; name: string | null; kind: DocumentKind; suggested_kind: DocumentKind }[]>;
  reviewDraft(id: string, decision: 'accept' | 'discard'): Promise<AiDraft>;
  legalEntities(signal?: AbortSignal): Promise<{ id: string; name: string; country_code: string }[]>;
  templates(signal?: AbortSignal): Promise<Template[]>;
  createTemplate(input: { name: string; legal_entity_id: string | null; description: string | null }): Promise<Template>;
  addTemplateTask(templateId: string, input: TemplateTaskInput): Promise<Template>;
  removeTemplateTask(taskId: string): Promise<Template>;
  documentRequirements(legalEntityId: string, signal?: AbortSignal): Promise<DocumentRequirement[]>;
  plans(q: { status?: string; q?: string; page: number }, signal?: AbortSignal): Promise<Page<Plan>>;
  plan(id: string, signal?: AbortSignal): Promise<Plan>;
  startPlan(input: { employee_id: string; template_id: string; start_date: string }): Promise<Plan>;
  cancelPlan(id: string): Promise<Plan>;
  myTasks(signal?: AbortSignal): Promise<OnboardingTask[]>;
  completeTask(id: string, note: string | null): Promise<OnboardingTask>;
  uploadTaskDocument(id: string, file: File): Promise<OnboardingTask>;
  reviewTask(id: string, decision: 'accept' | 'return', note: string | null): Promise<OnboardingTask>;
  skipTask(id: string, note: string): Promise<OnboardingTask>;
  downloadTaskDocument(id: string): Promise<void>;
}

const v1 = '/api/v1';
const data = async <T>(p: Promise<{ data: T }>) => (await p).data;
async function write<T>(method: 'POST' | 'PATCH' | 'DELETE', path: string, body?: unknown): Promise<T> {
  await ensureCsrfCookie();
  return data(apiRequest<{ data: T }>(method, path, { body: method === 'DELETE' ? undefined : body ?? {} }));
}
const form = (fields: Record<string, string | File | null | undefined>) => {
  const f = new FormData();
  for (const [k, v] of Object.entries(fields)) if (v !== null && v !== undefined && v !== '') f.append(k, v);
  return f;
};

export const httpRecruitmentSource: RecruitmentSource = {
  settings: (signal) => data(apiRequest('GET', `${v1}/recruitment/settings`, { signal })),
  saveSettings: (input) => write('PATCH', `${v1}/recruitment/settings`, input),
  addStage: (name) => write('POST', `${v1}/recruitment/stages`, { name }),
  renameStage: (id, name) => write('PATCH', `${v1}/recruitment/stages/${id}`, { name }),
  removeStage: (id) => write('DELETE', `${v1}/recruitment/stages/${id}`),
  openings: (q, signal) => apiRequest('GET', `${v1}/recruitment/openings`, { query: { page: String(q.page), status: q.status, q: q.q }, signal }),
  opening: (id, signal) => data(apiRequest('GET', `${v1}/recruitment/openings/${id}`, { signal })),
  saveOpening: (input, id) => (id ? write('PATCH', `${v1}/recruitment/openings/${id}`, input) : write('POST', `${v1}/recruitment/openings`, input)),
  openingAction: (id, action) => write('POST', `${v1}/recruitment/openings/${id}/${action}`),
  board: (q, signal) => data(apiRequest('GET', `${v1}/recruitment/board`, { query: { opening_id: q.opening_id, q: q.q }, signal })),
  application: (id, signal) => data(apiRequest('GET', `${v1}/recruitment/applications/${id}`, { signal })),
  addCandidate: ({ resume, consent_confirmed, ...input }) => write('POST', `${v1}/recruitment/applications`, form({ ...input, resume, consent_confirmed: consent_confirmed ? '1' : '0' })),
  move: (id, stageId, version) => write('POST', `${v1}/recruitment/applications/${id}/move`, { stage_id: stageId, version }),
  reject: (id, reason, version) => write('POST', `${v1}/recruitment/applications/${id}/reject`, { reason, version }),
  hold: (id, onHold) => write('POST', `${v1}/recruitment/applications/${id}/hold`, { on_hold: onHold }),
  erase: async (id, reason) => { await write('POST', `${v1}/recruitment/applications/${id}/erase`, { reason }); },
  uploadDocument: (applicationId, file, kind) => write('POST', `${v1}/recruitment/applications/${applicationId}/documents`, form({ file, kind })),
  setDocumentKind: (id, kind) => write('PATCH', `${v1}/recruitment/documents/${id}`, { kind }),
  downloadDocument: (id) => apiDownload(`${v1}/recruitment/documents/${id}/download`, {}, 'document'),
  interviews: (q, signal) => data(apiRequest('GET', `${v1}/recruitment/interviews`, { query: { scope: q.scope, application_id: q.application_id, upcoming: q.upcoming ? '1' : undefined }, signal })),
  schedule: (input) => write('POST', `${v1}/recruitment/interviews`, input),
  interviewAction: (id, action) => write('POST', `${v1}/recruitment/interviews/${id}/${action}`),
  submitFeedback: (id, input) => write('POST', `${v1}/recruitment/interviews/${id}/feedback`, input),
  panelUsers: async (search, signal) => (await apiRequest<{ data: PanelUser[] }>('GET', `${v1}/users`, { query: { per_page: '100' }, signal })).data
    .filter((u) => u.name.toLowerCase().includes(search.trim().toLowerCase())).map((u) => ({ id: u.id, name: u.name })),
  offers: (applicationId, signal) => data(apiRequest('GET', `${v1}/recruitment/applications/${applicationId}/offers`, { signal })),
  createOffer: (applicationId, input) => write('POST', `${v1}/recruitment/applications/${applicationId}/offers`, input),
  offerAction: (id, action, note) => write('POST', `${v1}/recruitment/offers/${id}/${action}`, { note }),
  hire: (applicationId, input) => write('POST', `${v1}/recruitment/applications/${applicationId}/hire`, input),
  drafts: (applicationId, signal) => data(apiRequest('GET', `${v1}/recruitment/applications/${applicationId}/ai-drafts`, { signal })),
  summarize: (applicationId) => write('POST', `${v1}/recruitment/applications/${applicationId}/ai/summary`),
  organizeDocuments: (applicationId) => write('POST', `${v1}/recruitment/applications/${applicationId}/ai/organize-documents`),
  reviewDraft: (id, decision) => write('POST', `${v1}/recruitment/ai-drafts/${id}/review`, { decision }),
  legalEntities: (signal) => data(apiRequest('GET', `${v1}/legal-entities`, { signal })),
  templates: (signal) => data(apiRequest('GET', `${v1}/onboarding/templates`, { signal })),
  createTemplate: (input) => write('POST', `${v1}/onboarding/templates`, input),
  addTemplateTask: (templateId, input) => write('POST', `${v1}/onboarding/templates/${templateId}/tasks`, input),
  removeTemplateTask: (taskId) => write('DELETE', `${v1}/onboarding/template-tasks/${taskId}`),
  documentRequirements: (legalEntityId, signal) => data(apiRequest('GET', `${v1}/onboarding/document-requirements`, { query: { legal_entity_id: legalEntityId }, signal })),
  plans: (q, signal) => apiRequest('GET', `${v1}/onboarding/plans`, { query: { page: String(q.page), status: q.status, q: q.q }, signal }),
  plan: (id, signal) => data(apiRequest('GET', `${v1}/onboarding/plans/${id}`, { signal })),
  startPlan: (input) => write('POST', `${v1}/onboarding/plans`, input),
  cancelPlan: (id) => write('POST', `${v1}/onboarding/plans/${id}/cancel`),
  myTasks: (signal) => data(apiRequest('GET', `${v1}/onboarding/my-tasks`, { signal })),
  completeTask: (id, note) => write('POST', `${v1}/onboarding/tasks/${id}/complete`, { note }),
  uploadTaskDocument: (id, file) => write('POST', `${v1}/onboarding/tasks/${id}/upload`, form({ file })),
  reviewTask: (id, decision, note) => write('POST', `${v1}/onboarding/tasks/${id}/review`, { decision, note }),
  skipTask: (id, note) => write('POST', `${v1}/onboarding/tasks/${id}/skip`, { note }),
  downloadTaskDocument: (id) => apiDownload(`${v1}/onboarding/tasks/${id}/document`, {}, 'document'),
};

const notConnected = () => Promise.reject(new ApiNotConnectedError());
export const disconnectedRecruitmentSource: RecruitmentSource = Object.fromEntries(
  Object.keys(httpRecruitmentSource).map((key) => [key, notConnected]),
) as unknown as RecruitmentSource;

const RecruitmentSourceContext = createContext<RecruitmentSource>(disconnectedRecruitmentSource);
export const RecruitmentSourceProvider = RecruitmentSourceContext.Provider;
export const useRecruitmentSource = () => useContext(RecruitmentSourceContext);

/** Public careers site: no session. The organization is chosen only by its careers address. */
export interface CareersOpening {
  slug: string; title: string; summary: string | null; description: string | null; requirements: string | null; employment_type: string | null; workplace: Workplace;
  department: string | null; location: string | null; closes_on: string | null; published_at: string | null; open: boolean;
}
export interface CareersSite { organization: string; headline: string | null; intro: string | null; consent: { version: string; text: string; retention_days: number }; openings: CareersOpening[] }
export interface ApplyInput { first_name: string; last_name: string; email: string; phone: string; location: string; linkedin_url: string; cover_letter: string; resume: File | null; consent: boolean; consent_version: string; website: string }
export interface CareersSource {
  site(site: string, signal?: AbortSignal): Promise<CareersSite>;
  opening(site: string, slug: string, signal?: AbortSignal): Promise<CareersOpening>;
  apply(site: string, slug: string, input: ApplyInput): Promise<void>;
}
const pub = (site: string) => `${v1}/public/careers/${encodeURIComponent(site)}`;
export const httpCareersSource: CareersSource = {
  site: (site, signal) => data(apiRequest('GET', pub(site), { signal })),
  opening: (site, slug, signal) => data(apiRequest('GET', `${pub(site)}/jobs/${encodeURIComponent(slug)}`, { signal })),
  apply: async (site, slug, { resume, consent, ...input }) => {
    await write('POST', `${pub(site)}/jobs/${encodeURIComponent(slug)}/apply`, form({ ...input, resume, consent: consent ? '1' : '0' }));
  },
};
export const disconnectedCareersSource: CareersSource = { site: notConnected, opening: notConnected, apply: notConnected };
const CareersSourceContext = createContext<CareersSource>(disconnectedCareersSource);
export const CareersSourceProvider = CareersSourceContext.Provider;
export const useCareersSource = () => useContext(CareersSourceContext);
