/** Types for the Developer Platform portal. */

export interface Paginated<T> {
  data: T[];
  meta: { page: number; per_page: number; total: number };
}

export interface DeveloperAccount {
  id: string;
  name: string;
  email: string;
  created_at: string;
}

export type ApplicationStatus = 'active' | 'suspended';

export interface DeveloperApplication {
  id: string;
  name: string;
  description: string;
  scopes: string[];
  status: ApplicationStatus;
  created_at: string;
  updated_at: string;
}

export type ApiKeyStatus = 'active' | 'revoked';

export interface ApiKey {
  id: string;
  name: string;
  prefix: string;
  scopes: string[];
  status: ApiKeyStatus;
  expires_at: string | null;
  last_used_at: string | null;
  created_at: string;
  revoked_at: string | null;
}

/** Returned once, on issue/rotate — includes the plaintext `key`. */
export interface IssuedApiKey extends ApiKey {
  key: string;
  notice: string;
}

export interface Webhook {
  id: string;
  url: string;
  events: string[];
  status: 'active' | 'disabled';
  secret?: string;
  created_at: string;
  updated_at: string;
}

export interface WebhookDelivery {
  id: string;
  event_id: string;
  event: string;
  status: 'pending' | 'delivered' | 'retrying' | 'failed';
  attempts: number;
  last_response_code: number | null;
  created_at: string;
  delivered_at: string | null;
}

export interface Usage {
  application_id: string;
  quota: { daily_used: number; daily_limit: number; monthly_used: number; monthly_limit: number };
  rate_limit: { per_minute: number; burst: number };
}

export interface ScopeInfo {
  scope: string;
  description: string;
}
