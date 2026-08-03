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

  /** POST a resource; returns the unwrapped `data`. */
  async post<T>(path: string, body?: unknown): Promise<T> {
    const res = await this.request(path, undefined, 'POST', body);
    return (res as { data: T }).data;
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

  // Restaurants + menus (scope restaurants:read)
  restaurants(query?: Record<string, string | number | undefined>) {
    return this.getPage('/restaurants', query);
  }
  restaurant(slug: string) {
    return this.get(`/restaurants/${encodeURIComponent(slug)}`);
  }
  restaurantMenu(id: string) {
    return this.get(`/restaurants/${encodeURIComponent(id)}/menu`);
  }

  // Products + categories (scope products:read)
  products(query?: Record<string, string | number | undefined>) {
    return this.getPage('/products', query);
  }
  product(slug: string) {
    return this.get(`/products/${encodeURIComponent(slug)}`);
  }
  productCategories() {
    return this.get('/product-categories');
  }

  // Nutrition (scope nutrition:read)
  nutritionItems(query?: Record<string, string | number | undefined>) {
    return this.getPage('/nutrition', query);
  }
  nutritionItem(id: string) {
    return this.get(`/nutrition/${encodeURIComponent(id)}`);
  }

  // Search (scope search:read)
  search(query: Record<string, string | number | undefined>) {
    return this.get('/search', query);
  }
  searchSuggestions(q: string, type?: string) {
    return this.get('/search/suggestions', { q, type });
  }
  searchFilters() {
    return this.get('/search/filters');
  }

  // Orders — customer-scoped, BOLA-enforced (scope orders:read / orders:write)
  orders(query?: Record<string, string | number | undefined>) {
    return this.getPage('/orders', query);
  }
  order(id: string) {
    return this.get(`/orders/${encodeURIComponent(id)}`);
  }
  orderStatus(id: string) {
    return this.get(`/orders/${encodeURIComponent(id)}/status`);
  }
  createOrder(body: { pickup?: boolean; note?: string | null; scheduled_for?: string | null; shipping_address?: unknown }) {
    return this.post('/orders', body);
  }
  cancelOrder(id: string) {
    return this.post(`/orders/${encodeURIComponent(id)}/cancel`);
  }

  private async request(
    path: string,
    query?: Record<string, string | number | boolean | undefined>,
    method: 'GET' | 'POST' = 'GET',
    body?: unknown,
  ): Promise<unknown> {
    const url = new URL(this.baseUrl + path);
    for (const [k, v] of Object.entries(query ?? {})) {
      if (v !== undefined) url.searchParams.set(k, String(v));
    }

    const controller = new AbortController();
    const timeout = this.config.timeoutMs ? setTimeout(() => controller.abort(), this.config.timeoutMs) : undefined;
    try {
      const headers: Record<string, string> = {
        Authorization: `Bearer ${this.config.apiKey}`,
        Accept: 'application/json',
      };
      if (body !== undefined) headers['Content-Type'] = 'application/json';
      const res = await this.fetchImpl(url.toString(), {
        method,
        headers,
        body: body !== undefined ? JSON.stringify(body) : undefined,
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

/**
 * Exchange OAuth2 client credentials for an access token. A standalone helper
 * (not on the client) because it authenticates the client, not an end user; the
 * returned `access_token` can be passed as the `apiKey` to {@link EruoFoodClient}.
 */
export interface OAuthTokenResponse {
  access_token: string;
  token_type: string;
  expires_in: number;
  scope: string;
  refresh_token?: string;
}

export async function oauthToken(
  params: Record<string, string>,
  opts: { baseUrl?: string; fetch?: typeof fetch } = {},
): Promise<OAuthTokenResponse> {
  const base = (opts.baseUrl ?? DEFAULT_BASE_URL).replace(/\/$/, '');
  const fetchImpl = opts.fetch ?? globalThis.fetch;
  const res = await fetchImpl(`${base}/oauth/token`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json' },
    body: new URLSearchParams(params).toString(),
  });
  const json = (await res.json()) as OAuthTokenResponse & { error?: string; error_description?: string };
  if (!res.ok) {
    throw new EruoFoodApiError(res.status, json.error ?? 'oauth_error', json.error_description ?? res.statusText);
  }
  return json;
}
