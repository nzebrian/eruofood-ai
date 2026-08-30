import { config } from '@config/env';
import { IDEMPOTENCY_HEADER } from '@lib/idempotency';
import { tokenStorage } from '@lib/tokenStorage';

/**
 * REST client for the EruoFood API. Attaches the bearer access token, unwraps
 * the standard `{ data }` envelope, and transparently refreshes the access
 * token once on a 401 before retrying (MASTER_PLAN.md §6).
 *
 * ## Idempotency (M43)
 *
 * `postIdempotent` takes the key as an argument and never mints one. That is
 * the whole design: a transport layer that generated keys would mint a second
 * one on the 401 refresh replay below, turning one logical operation into two
 * as far as the server is concerned — which is the exact duplicate-charge this
 * exists to prevent. Because the key travels inside `init.headers` and the
 * replay re-uses the same `init` object, the retried request carries the
 * original key without any code here having to remember it.
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
  /**
   * POST a money-moving operation under a caller-supplied idempotency key.
   *
   * The key is required rather than defaulted, so an operation cannot become
   * un-idempotent by omission. It is placed in `init.headers`, which `request`
   * hands unchanged to the 401 replay — so the replay sends the same key while
   * still picking up the freshly refreshed `Authorization`.
   */
  postIdempotent: <T>(path: string, payload: unknown, idempotencyKey: string) => {
    if (idempotencyKey === '') {
      // An empty header is worse than none: the server would treat the request
      // as unkeyed and the caller would believe it was protected.
      throw new Error('A money-moving request needs a non-empty idempotency key.');
    }

    return request<T>(path, {
      method: 'POST',
      body: JSON.stringify(payload ?? {}),
      headers: { [IDEMPOTENCY_HEADER]: idempotencyKey },
    });
  },
  put: <T>(path: string, payload?: unknown) =>
    request<T>(path, { method: 'PUT', body: JSON.stringify(payload ?? {}) }),
  patch: <T>(path: string, payload?: unknown) =>
    request<T>(path, { method: 'PATCH', body: JSON.stringify(payload ?? {}) }),
  delete: <T>(path: string) => request<T>(path, { method: 'DELETE' }),
};
