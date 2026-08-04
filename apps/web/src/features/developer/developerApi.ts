import { apiClient } from '@lib/apiClient';
import type {
  ApiKey,
  DeveloperAccount,
  DeveloperApplication,
  IssuedApiKey,
  Paginated,
  ScopeInfo,
  Usage,
  Webhook,
  WebhookDelivery,
} from './types';

/**
 * Client for the Developer Platform (portal) endpoints. These are the internal,
 * JWT-authenticated management APIs at /v1/developer — distinct from the public,
 * API-key-authenticated surface at /public/v1.
 */
export const developerApi = {
  // Public meta (no auth) — the scope catalogue for the portal UI.
  scopes: () => apiClient.get<{ scopes: ScopeInfo[] }>('/public/v1/scopes'),

  // Developer account
  register: (name: string, email: string) =>
    apiClient.post<DeveloperAccount>('/v1/developer/register', { name, email }),
  me: () => apiClient.get<DeveloperAccount>('/v1/developer/me'),

  // Applications
  applications: () => apiClient.getPage<Paginated<DeveloperApplication>>('/v1/developer/applications'),
  createApplication: (name: string, description: string, scopes: string[]) =>
    apiClient.post<DeveloperApplication>('/v1/developer/applications', { name, description, scopes }),
  setScopes: (id: string, scopes: string[]) =>
    apiClient.put<DeveloperApplication>(`/v1/developer/applications/${id}/scopes`, { scopes }),
  suspend: (id: string) => apiClient.post<DeveloperApplication>(`/v1/developer/applications/${id}/suspend`, {}),

  // API keys
  keys: (appId: string) => apiClient.get<{ keys: ApiKey[] }>(`/v1/developer/applications/${appId}/keys`),
  issueKey: (appId: string, name: string, scopes: string[], ttlDays?: number) =>
    apiClient.post<IssuedApiKey>(`/v1/developer/applications/${appId}/keys`, { name, scopes, ttl_days: ttlDays }),
  rotateKey: (keyId: string) => apiClient.post<IssuedApiKey>(`/v1/developer/keys/${keyId}/rotate`, {}),
  revokeKey: (keyId: string) => apiClient.delete<{ revoked: boolean }>(`/v1/developer/keys/${keyId}`),

  // Webhooks
  webhooks: (appId: string) => apiClient.get<{ webhooks: Webhook[] }>(`/v1/developer/applications/${appId}/webhooks`),
  createWebhook: (appId: string, url: string, events: string[]) =>
    apiClient.post<Webhook>(`/v1/developer/applications/${appId}/webhooks`, { url, events }),
  rotateWebhookSecret: (appId: string, id: string) =>
    apiClient.post<Webhook>(`/v1/developer/applications/${appId}/webhooks/${id}/rotate-secret`, {}),
  disableWebhook: (appId: string, id: string) =>
    apiClient.delete<Webhook>(`/v1/developer/applications/${appId}/webhooks/${id}`),
  deliveries: (appId: string, id: string) =>
    apiClient.get<{ deliveries: WebhookDelivery[] }>(`/v1/developer/applications/${appId}/webhooks/${id}/deliveries`),

  // Usage
  usage: (appId: string) => apiClient.get<Usage>(`/v1/developer/applications/${appId}/usage`),
};
