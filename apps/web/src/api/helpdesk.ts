import { createContext, useContext } from 'react';
import { ApiNotConnectedError } from './dashboard';
import { apiDownload, apiRequest, ensureCsrfCookie } from './http';
import type { Page } from './employees';

/** Mirrors the Helpdesk API (docs/architecture/helpdesk.md). */
export type TicketStatus = 'open' | 'in_progress' | 'waiting' | 'resolved' | 'closed';
export type TicketPriority = 'low' | 'normal' | 'high' | 'urgent';
export type ArticleKind = 'faq' | 'article';
export interface Ref { id: string; name: string }

export interface HelpdeskCategory { id: string; name: string; description: string | null; restricted: boolean; active: boolean; sort_order: number; agents?: Ref[] }
export interface CategoryInput { name: string; description: string | null; restricted: boolean; active: boolean; agent_ids?: string[] }
export interface Attachment { id: string; name: string | null; size: number | null; mime_type: string | null; internal: boolean }
export interface TicketComment { id: string; body: string; internal: boolean; created_at: string | null; author: Ref & { staff: boolean }; attachments: Attachment[] }
export interface Ticket {
  id: string; number: number; subject: string; status: TicketStatus; priority: TicketPriority;
  category: { id: string; name: string; restricted: boolean }; requester: Ref; assignee: Ref | null;
  due_at: string | null; overdue: boolean; first_response_at: string | null; resolved_at: string | null; closed_at: string | null;
  created_at: string | null; updated_at: string | null; version: number; next: TicketStatus[];
  can: { work: boolean; assign: boolean; take: boolean; internal_notes: boolean; comment: boolean };
  description?: string; comments?: TicketComment[]; attachments?: Attachment[];
}
export interface TicketQuery { page: number; scope?: 'mine' | 'assigned' | 'queue'; status?: TicketStatus | 'active' | ''; priority?: TicketPriority | ''; q?: string; overdue?: boolean }
export interface NewTicket { category_id: string; subject: string; description: string; priority: TicketPriority; files: File[] }
export interface Article {
  id: string; kind: ArticleKind; title: string; summary: string | null; status: 'draft' | 'published'; category: Ref | null;
  published_at: string | null; updated_at: string | null; sort_order: number; body?: string; can: { edit: boolean };
}
export interface ArticleInput { kind: ArticleKind; title: string; summary: string | null; body: string; category_id: string | null }
export type DueHours = Record<TicketPriority, number | null>;

export interface HelpdeskSource {
  tickets(q: TicketQuery, signal?: AbortSignal): Promise<Page<Ticket>>;
  ticket(id: string, signal?: AbortSignal): Promise<Ticket>;
  createTicket(input: NewTicket): Promise<Ticket>;
  updateTicket(id: string, input: { priority?: TicketPriority; category_id?: string }, version: number): Promise<Ticket>;
  assign(id: string, assigneeId: string | null, version: number): Promise<Ticket>;
  setStatus(id: string, status: TicketStatus, version: number): Promise<Ticket>;
  comment(id: string, body: string, internal: boolean, files: File[]): Promise<Ticket>;
  downloadAttachment(id: string): Promise<void>;
  agents(signal?: AbortSignal): Promise<Ref[]>;
  categories(signal?: AbortSignal): Promise<HelpdeskCategory[]>;
  saveCategory(input: CategoryInput, id?: string): Promise<HelpdeskCategory>;
  settings(signal?: AbortSignal): Promise<{ due_hours: DueHours }>;
  saveSettings(dueHours: DueHours): Promise<{ due_hours: DueHours }>;
  articles(q: { kind?: ArticleKind; q?: string; page: number }, signal?: AbortSignal): Promise<Page<Article>>;
  article(id: string, signal?: AbortSignal): Promise<Article>;
  saveArticle(input: ArticleInput, id?: string): Promise<Article>;
  publishArticle(id: string, publish: boolean): Promise<Article>;
  deleteArticle(id: string): Promise<void>;
}

const v1 = '/api/v1';
const data = async <T,>(p: Promise<{ data: T }>) => (await p).data;
async function write<T>(method: 'POST' | 'PATCH' | 'DELETE', path: string, body?: unknown): Promise<T> {
  await ensureCsrfCookie();
  return data(apiRequest<{ data: T }>(method, path, { body: method === 'DELETE' ? undefined : body ?? {} }));
}
const withFiles = (fields: Record<string, string>, files: File[]) => {
  const f = new FormData();
  for (const [k, v] of Object.entries(fields)) if (v !== '') f.append(k, v);
  files.forEach((file) => f.append('files[]', file));
  return f;
};

export const httpHelpdeskSource: HelpdeskSource = {
  tickets: (q, signal) => apiRequest('GET', `${v1}/helpdesk/tickets`, {
    query: { page: String(q.page), scope: q.scope, status: q.status || undefined, priority: q.priority || undefined, q: q.q || undefined, overdue: q.overdue ? '1' : undefined }, signal,
  }),
  ticket: (id, signal) => data(apiRequest('GET', `${v1}/helpdesk/tickets/${id}`, { signal })),
  createTicket: ({ files, ...input }) => write('POST', `${v1}/helpdesk/tickets`, withFiles(input, files)),
  updateTicket: (id, input, version) => write('PATCH', `${v1}/helpdesk/tickets/${id}`, { ...input, version }),
  assign: (id, assigneeId, version) => write('POST', `${v1}/helpdesk/tickets/${id}/assign`, { assignee_id: assigneeId, version }),
  setStatus: (id, status, version) => write('POST', `${v1}/helpdesk/tickets/${id}/status`, { status, version }),
  comment: (id, body, internal, files) => write('POST', `${v1}/helpdesk/tickets/${id}/comments`, withFiles({ body, internal: internal ? '1' : '0' }, files)),
  downloadAttachment: (id) => apiDownload(`${v1}/helpdesk/attachments/${id}/download`, {}, 'attachment'),
  agents: (signal) => data(apiRequest('GET', `${v1}/helpdesk/agents`, { signal })),
  categories: (signal) => data(apiRequest('GET', `${v1}/helpdesk/categories`, { signal })),
  saveCategory: (input, id) => (id ? write('PATCH', `${v1}/helpdesk/categories/${id}`, input) : write('POST', `${v1}/helpdesk/categories`, input)),
  settings: (signal) => data(apiRequest('GET', `${v1}/helpdesk/settings`, { signal })),
  saveSettings: (dueHours) => write('PATCH', `${v1}/helpdesk/settings`, { due_hours: dueHours }),
  articles: (q, signal) => apiRequest('GET', `${v1}/helpdesk/articles`, { query: { page: String(q.page), kind: q.kind, q: q.q || undefined }, signal }),
  article: (id, signal) => data(apiRequest('GET', `${v1}/helpdesk/articles/${id}`, { signal })),
  saveArticle: (input, id) => (id ? write('PATCH', `${v1}/helpdesk/articles/${id}`, input) : write('POST', `${v1}/helpdesk/articles`, input)),
  publishArticle: (id, publish) => write('POST', `${v1}/helpdesk/articles/${id}/${publish ? 'publish' : 'unpublish'}`),
  deleteArticle: async (id) => { await ensureCsrfCookie(); await apiRequest('DELETE', `${v1}/helpdesk/articles/${id}`); },
};

const notConnected = () => Promise.reject(new ApiNotConnectedError());
export const disconnectedHelpdeskSource: HelpdeskSource = Object.fromEntries(
  Object.keys(httpHelpdeskSource).map((key) => [key, notConnected]),
) as unknown as HelpdeskSource;
const HelpdeskSourceContext = createContext<HelpdeskSource>(disconnectedHelpdeskSource);
export const HelpdeskSourceProvider = HelpdeskSourceContext.Provider;
export const useHelpdeskSource = () => useContext(HelpdeskSourceContext);
