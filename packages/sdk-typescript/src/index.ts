/**
 * EruoFood Public API — TypeScript SDK (foundation).
 *
 * A thin, dependency-free client over the public API: API-key auth,
 * configuration, typed errors, and a pagination helper. Intentionally minimal —
 * resource methods are thin wrappers over `get`, so new endpoints need no SDK
 * release.
 */

export interface EruoFoodConfig {
  apiKey: string;
  baseUrl?: string;
  /** Override the fetch implementation (e.g. for Node < 18 or testing). */
  fetch?: typeof fetch;
  timeoutMs?: number;
}

export interface Pagination {
  page: number;
  per_page: number;
  total: number;
  last_page: number;
  has_more: boolean;
}

export interface Page<T> {
  data: T[];
  meta: { pagination: Pagination; version: string };
}

/** Thrown for any non-2xx response; carries the standard error envelope fields. */
export class EruoFoodApiError extends Error {
  constructor(
    public readonly status: number,
    public readonly code: string,
    message: string,
    public readonly details?: unknown,
  ) {
    super(message);
    this.name = 'EruoFoodApiError';
  }
}

const DEFAULT_BASE_URL = 'https://api.eruofood.example/api/public/v1';

export class EruoFoodClient {
  private readonly baseUrl: string;
  private readonly fetchImpl: typeof fetch;

  constructor(private readonly config: EruoFoodConfig) {
    if (!config.apiKey) throw new Error('EruoFoodClient requires an apiKey.');
    this.baseUrl = (config.baseUrl ?? DEFAULT_BASE_URL).replace(/\/$/, '');
    this.fetchImpl = config.fetch ?? globalThis.fetch;
  }

  /** GET a single resource; returns the unwrapped `data`. */
  async get<T>(path: string, query?: Record<string, string | number | boolean | undefined>): Promise<T> {
    const body = await this.request(path, query);
    return (body as { data: T }).data;
  }

  /** GET a paginated collection; returns the full `{ data, meta }` page. */
  async getPage<T>(path: string, query?: Record<string, string | number | boolean | undefined>): Promise<Page<T>> {
    return (await this.request(path, query)) as Page<T>;
  }

  /** Iterate every item across all pages, fetching lazily. */
  async *paginate<T>(path: string, query: Record<string, string | number | boolean | undefined> = {}): AsyncGenerator<T> {
    let page = Number(query.page ?? 1);
    for (;;) {
      const result = await this.getPage<T>(path, { ...query, page });
      for (const item of result.data) yield item;
      if (!result.meta.pagination.has_more) return;
      page += 1;
    }
  }

  // --- Convenience resource methods (thin wrappers) ---
  foods(query?: Record<string, string | number | undefined>) {
    return this.getPage('/foods', query);
  }
  food(slug: string) {
    return this.get(`/foods/${encodeURIComponent(slug)}`);
  }
  recipes(query?: Record<string, string | number | undefined>) {
    return this.getPage('/recipes', query);
  }
  recipe(slug: string) {
    return this.get(`/recipes/${encodeURIComponent(slug)}`);
  }

  private async request(path: string, query?: Record<string, string | number | boolean | undefined>): Promise<unknown> {
    const url = new URL(this.baseUrl + path);
    for (const [k, v] of Object.entries(query ?? {})) {
      if (v !== undefined) url.searchParams.set(k, String(v));
    }

    const controller = new AbortController();
    const timeout = this.config.timeoutMs ? setTimeout(() => controller.abort(), this.config.timeoutMs) : undefined;
    try {
      const res = await this.fetchImpl(url.toString(), {
        method: 'GET',
        headers: { Authorization: `Bearer ${this.config.apiKey}`, Accept: 'application/json' },
        signal: controller.signal,
      });
      const text = await res.text();
      const json = text ? (JSON.parse(text) as Record<string, unknown>) : {};
      if (!res.ok) {
        const err = (json.error ?? {}) as { code?: string; message?: string; details?: unknown };
        throw new EruoFoodApiError(res.status, err.code ?? 'error', err.message ?? res.statusText, err.details);
      }
      return json;
    } finally {
      if (timeout) clearTimeout(timeout);
    }
  }
}
