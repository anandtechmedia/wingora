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
  if (body !== undefined) headers['Content-Type'] = 'application/json';
  const token = method === 'GET' ? undefined : xsrfToken();
  if (token) headers['X-XSRF-TOKEN'] = token;

  const response = await fetch(url, { method, headers, body: body === undefined ? undefined : JSON.stringify(body), credentials: 'include', signal });
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

/** Sanctum sets the XSRF-TOKEN cookie before the first state-changing request. */
export async function ensureCsrfCookie(): Promise<void> {
  await fetch(`${baseUrl}/sanctum/csrf-cookie`, { credentials: 'include', headers: { Accept: 'application/json' } });
}
