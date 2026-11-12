import { createContext, useContext } from 'react';
import { ApiNotConnectedError } from './dashboard';
import { apiDownload, apiRequest, ensureCsrfCookie } from './http';
import type { Page } from './employees';

/** Mirrors the Document requests API (docs/architecture/document-requests.md). */
export type RequestStatus = 'pending_manager' | 'pending_hr' | 'approved' | 'rejected' | 'delivered' | 'cancelled';
export type Delivery = 'generated' | 'upload';
export type FieldType = 'text' | 'textarea' | 'date';
export interface Ref { id: string; name: string }
export interface TemplateField { key: string; label: string; type: FieldType; required: boolean }
export interface DocumentTemplate {
  id: string; name: string; description: string | null; delivery: Delivery; fields: TemplateField[]; requires_manager: boolean; includes_salary: boolean;
  legal_entity: (Ref & { country_code: string }) | null; active: boolean; sort_order: number; body?: string | null; placeholders?: string[];
}
export interface TemplateInput { name: string; description: string | null; delivery: Delivery; body: string | null; fields: TemplateField[]; requires_manager: boolean; includes_salary: boolean; active: boolean }
export interface Decision { by: string | null; at: string }
export interface DocumentRequest {
  id: string; number: number; status: RequestStatus;
  template: { id: string; name: string; delivery: Delivery; includes_salary: boolean; fields: TemplateField[] };
  employee: (Ref & { employee_number: string }) | null; requester: Ref; answers: Record<string, string>; manager_step: boolean;
  manager_decision: Decision | null; decision: Decision | null; rejection_reason: string | null; delivered_at: string | null;
  document: { name: string; size: number; mime_type: string } | null; created_at: string | null; version: number;
  can: { decide: boolean; deliver: boolean; cancel: boolean; download: boolean };
}
export interface RequestQuery { page: number; scope: 'mine' | 'approvals' | 'all'; status?: RequestStatus | 'open' | ''; q?: string }

export interface DocumentsSource {
  templates(signal?: AbortSignal): Promise<DocumentTemplate[]>;
  saveTemplate(input: TemplateInput, id?: string): Promise<DocumentTemplate>;
  requests(q: RequestQuery, signal?: AbortSignal): Promise<Page<DocumentRequest>>;
  request(id: string, signal?: AbortSignal): Promise<DocumentRequest>;
  submit(templateId: string, answers: Record<string, string>): Promise<DocumentRequest>;
  decide(id: string, decision: 'approve' | 'reject', reason: string | null, version: number): Promise<DocumentRequest>;
  cancel(id: string, version: number): Promise<DocumentRequest>;
  deliver(id: string, version: number, input: { salary?: string; file?: File | null }): Promise<DocumentRequest>;
  download(id: string): Promise<void>;
}

const v1 = '/api/v1';
const data = async <T,>(p: Promise<{ data: T }>) => (await p).data;
async function write<T>(method: 'POST' | 'PATCH', path: string, body: unknown): Promise<T> {
  await ensureCsrfCookie();
  return data(apiRequest<{ data: T }>(method, path, { body }));
}

export const httpDocumentsSource: DocumentsSource = {
  templates: (signal) => data(apiRequest('GET', `${v1}/document-templates`, { signal })),
  saveTemplate: (input, id) => (id ? write('PATCH', `${v1}/document-templates/${id}`, input) : write('POST', `${v1}/document-templates`, input)),
  requests: (q, signal) => apiRequest('GET', `${v1}/document-requests`, { query: { page: String(q.page), scope: q.scope, status: q.status || undefined, q: q.q || undefined }, signal }),
  request: (id, signal) => data(apiRequest('GET', `${v1}/document-requests/${id}`, { signal })),
  submit: (templateId, answers) => write('POST', `${v1}/document-requests`, { template_id: templateId, answers }),
  decide: (id, decision, reason, version) => write('POST', `${v1}/document-requests/${id}/decide`, { decision, reason, version }),
  cancel: (id, version) => write('POST', `${v1}/document-requests/${id}/cancel`, { version }),
  deliver: (id, version, { salary, file }) => {
    const f = new FormData();
    f.append('version', String(version));
    if (salary) f.append('salary', salary);
    if (file) f.append('file', file);
    return write('POST', `${v1}/document-requests/${id}/deliver`, f);
  },
  download: (id) => apiDownload(`${v1}/document-requests/${id}/document`, {}, 'document'),
};

const notConnected = () => Promise.reject(new ApiNotConnectedError());
export const disconnectedDocumentsSource: DocumentsSource = Object.fromEntries(
  Object.keys(httpDocumentsSource).map((key) => [key, notConnected]),
) as unknown as DocumentsSource;
const DocumentsSourceContext = createContext<DocumentsSource>(disconnectedDocumentsSource);
export const DocumentsSourceProvider = DocumentsSourceContext.Provider;
export const useDocumentsSource = () => useContext(DocumentsSourceContext);
