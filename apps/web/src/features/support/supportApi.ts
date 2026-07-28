import { apiClient } from '@lib/apiClient';
import type {
  Article,
  CustomerProfile,
  Interaction,
  Paginated,
  SupportDashboard,
  Ticket,
  TicketSummary,
} from './types';

function query(params: Record<string, string | number | undefined>): string {
  const q = new URLSearchParams();
  for (const [k, v] of Object.entries(params)) {
    if (v !== undefined && v !== '') q.set(k, String(v));
  }
  const s = q.toString();
  return s ? `?${s}` : '';
}

/** Client for the Customer Support REST endpoints (mounted at /support). */
export const supportApi = {
  // Customer portal
  myTickets: () => apiClient.getPage<Paginated<TicketSummary>>('/support/tickets'),
  ticket: (id: string) => apiClient.get<Ticket>(`/support/tickets/${id}`),
  openTicket: (payload: { subject: string; category: string; body: string; priority?: string }) =>
    apiClient.post<Ticket>('/support/tickets', payload),
  reply: (id: string, body: string) => apiClient.post<Ticket>(`/support/tickets/${id}/reply`, { body }),
  submitCsat: (id: string, score: number, comment?: string) =>
    apiClient.post<{ score: number }>(`/support/tickets/${id}/csat`, { score, comment }),

  // Agent workspace
  queue: (status: string, unassigned: boolean) =>
    apiClient.getPage<Paginated<TicketSummary>>(
      `/support/agent/tickets${query({ status, unassigned: unassigned ? 'true' : undefined })}`,
    ),
  agentTicket: (id: string) => apiClient.get<Ticket>(`/support/agent/tickets/${id}`),
  assign: (id: string) => apiClient.post<Ticket>(`/support/agent/tickets/${id}/assign`),
  agentReply: (id: string, body: string) => apiClient.post<Ticket>(`/support/agent/tickets/${id}/reply`, { body }),
  note: (id: string, body: string) => apiClient.post<Ticket>(`/support/agent/tickets/${id}/notes`, { body }),
  setStatus: (id: string, status: string) => apiClient.put<Ticket>(`/support/agent/tickets/${id}/status`, { status }),
  escalate: (id: string) => apiClient.post<Ticket>(`/support/agent/tickets/${id}/escalate`, {}),
  aiSummary: (id: string) => apiClient.get<{ summary: string }>(`/support/agent/tickets/${id}/ai/summary`),
  aiSuggest: (id: string) => apiClient.get<{ suggestion: string }>(`/support/agent/tickets/${id}/ai/suggest`),

  // Knowledge base
  articles: (q: string) => apiClient.getPage<Paginated<Article>>(`/support/kb/articles${query({ q })}`),
  article: (slug: string) => apiClient.get<Article>(`/support/kb/articles/${slug}`),
  voteArticle: (slug: string, helpful: boolean) =>
    apiClient.post<Article>(`/support/kb/articles/${slug}/vote`, { helpful }),

  // CRM
  customers: (q: string, segment: string) =>
    apiClient.getPage<Paginated<CustomerProfile>>(`/support/crm/customers${query({ q, segment })}`),
  customer: (userId: string) => apiClient.get<CustomerProfile>(`/support/crm/customers/${userId}`),
  timeline: (userId: string) =>
    apiClient.getPage<Paginated<Interaction>>(`/support/crm/customers/${userId}/timeline`),
  generateInsight: (userId: string) =>
    apiClient.post<CustomerProfile>(`/support/crm/customers/${userId}/insight`, {}),

  // Admin dashboard
  dashboard: (days: number) => apiClient.get<SupportDashboard>(`/support/admin/dashboard${query({ days })}`),
};
