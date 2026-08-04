import { config } from '@config/env';
import { tokenStorage } from '@lib/tokenStorage';

/**
 * REST client for the EruoFood API. Attaches the bearer access token, unwraps
 * the standard `{ data }` envelope, and transparently refreshes the access
 * token once on a 401 before retrying (MASTER_PLAN.md §6).
 */

export interface ApiEnvelope<T> {
  data: T;
  meta?: Record<string, unknown>;
}

export interface ApiError {
  code: string;
  message: string;
  details?: unknown;
}

export class ApiRequestError extends Error {
  constructor(
    public readonly status: number,
    public readonly error: ApiError,
  ) {
    super(error.message);
    this.name = 'ApiRequestError';
  }
}

function authHeaders(): Record<string, string> {
  const tokens = tokenStorage.get();
  return tokens ? { Authorization: `Bearer ${tokens.accessToken}` } : {};
}

async function tryRefresh(): Promise<boolean> {
  const tokens = tokenStorage.get();
  if (!tokens) return false;

  const response = await fetch(`${config.apiBaseUrl}/auth/refresh`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ refresh_token: tokens.refreshToken }),
  });

  if (!response.ok) {
    tokenStorage.clear();
    return false;
  }

  const body = (await response.json()) as ApiEnvelope<{
    access_token: string;
    refresh_token: string;
  }>;
  tokenStorage.set({
    accessToken: body.data.access_token,
    refreshToken: body.data.refresh_token,
  });
  return true;
}

async function request<T>(path: string, init: RequestInit = {}, retry = true): Promise<T> {
  const response = await fetch(`${config.apiBaseUrl}${path}`, {
    ...init,
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      ...authHeaders(),
      ...init.headers,
    },
  });

  if (response.status === 401 && retry && !path.startsWith('/auth/')) {
    if (await tryRefresh()) {
      return request<T>(path, init, false);
    }
  }

  if (response.status === 204) {
    return undefined as T;
  }

  const body: unknown = await response.json().catch(() => null);

  if (!response.ok) {
    const err = (body as { error?: ApiError } | null)?.error ?? {
      code: 'UNKNOWN',
      message: response.statusText,
    };
    throw new ApiRequestError(response.status, err);
  }

  return (body as ApiEnvelope<T>).data;
}

/** Like request(), but returns the whole envelope (keeps `meta` for lists). */
async function requestEnvelope<T>(path: string, retry = true): Promise<T> {
  const response = await fetch(`${config.apiBaseUrl}${path}`, {
    headers: { Accept: 'application/json', ...authHeaders() },
  });

  if (response.status === 401 && retry && !path.startsWith('/auth/')) {
    if (await tryRefresh()) {
      return requestEnvelope<T>(path, false);
    }
  }

  const body: unknown = await response.json().catch(() => null);
  if (!response.ok) {
    const err = (body as { error?: ApiError } | null)?.error ?? {
      code: 'UNKNOWN',
      message: response.statusText,
    };
    throw new ApiRequestError(response.status, err);
  }
  return body as T;
}

export const apiClient = {
  get: <T>(path: string) => request<T>(path, { method: 'GET' }),
  /** GET a paginated/enveloped response ({ data, meta }). */
  getPage: <T>(path: string) => requestEnvelope<T>(path),
  post: <T>(path: string, payload?: unknown) =>
    request<T>(path, { method: 'POST', body: JSON.stringify(payload ?? {}) }),
  put: <T>(path: string, payload?: unknown) =>
    request<T>(path, { method: 'PUT', body: JSON.stringify(payload ?? {}) }),
  patch: <T>(path: string, payload?: unknown) =>
    request<T>(path, { method: 'PATCH', body: JSON.stringify(payload ?? {}) }),
  delete: <T>(path: string) => request<T>(path, { method: 'DELETE' }),
};
