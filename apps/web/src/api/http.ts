/** Minimal first-party API transport: same-origin cookies (Sanctum SPA), XSRF header on writes, JSON only. */
export class ApiError extends Error {
  constructor(public readonly status: number, public readonly body: unknown) {
    super(`HTTP ${status}`);
    this.name = 'ApiError';
  }
}

const baseUrl = (import.meta.env.VITE_API_BASE_URL as string | undefined)?.replace(/\/$/, '') ?? '';

function xsrfToken(): string | undefined {
  const match = /(?:^|;\s*)XSRF-TOKEN=([^;]+)/.exec(document.cookie);
  return match ? decodeURIComponent(match[1]!) : undefined;
}

export interface RequestOptions {
  query?: Record<string, string | null | undefined>;
  body?: unknown;
  signal?: AbortSignal;
}

export async function apiRequest<T>(method: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE', path: string, { query, body, signal }: RequestOptions = {}): Promise<T> {
  const url = new URL(baseUrl + path, window.location.origin);
  for (const [key, value] of Object.entries(query ?? {})) {
    if (value != null && value !== '') url.searchParams.set(key, value);
  }
  const headers: Record<string, string> = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
  const multipart = body instanceof FormData;
  if (body !== undefined && !multipart) headers['Content-Type'] = 'application/json';
  const token = method === 'GET' ? undefined : xsrfToken();
  if (token) headers['X-XSRF-TOKEN'] = token;

  const response = await fetch(url, { method, headers, body: body === undefined ? undefined : multipart ? body : JSON.stringify(body), credentials: 'include', signal });
  const text = await response.text();
  let data: unknown = null;
  try {
    data = text ? JSON.parse(text) : null;
  } catch {
    data = null;
  }
  if (!response.ok) throw new ApiError(response.status, data);
  return data as T;
}

/** Laravel's plain paginator JSON (`current_page`, `data`, ... at the top level), as some endpoints return it. */
export interface LaravelPaginator<T> { data: T[]; current_page: number; last_page: number; per_page: number; total: number }
export async function fromPaginator<T>(p: Promise<LaravelPaginator<T>>): Promise<{ data: T[]; meta: { current_page: number; last_page: number; per_page: number; total: number } }> {
  const r = await p;
  return { data: r.data, meta: { current_page: r.current_page, last_page: r.last_page, per_page: r.per_page, total: r.total } };
}

/** Fetches a file response (e.g. CSV export) and hands it to the browser as a download. */
export async function apiDownload(path: string, query: Record<string, string | null | undefined>, fallbackName: string): Promise<void> {
  const url = new URL(baseUrl + path, window.location.origin);
  for (const [key, value] of Object.entries(query)) {
    if (value != null && value !== '') url.searchParams.set(key, value);
  }
  const response = await fetch(url, { headers: { Accept: 'text/csv, application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'include' });
  if (!response.ok) throw new ApiError(response.status, null);
  const name = /filename="?([^";]+)"?/.exec(response.headers.get('Content-Disposition') ?? '')?.[1] ?? fallbackName;
  const href = URL.createObjectURL(await response.blob());
  const link = document.createElement('a');
  link.href = href;
  link.download = name;
  link.click();
  URL.revokeObjectURL(href);
}

/** Sanctum sets the XSRF-TOKEN cookie before the first state-changing request. */
export async function ensureCsrfCookie(): Promise<void> {
  await fetch(`${baseUrl}/sanctum/csrf-cookie`, { credentials: 'include', headers: { Accept: 'application/json' } });
}
