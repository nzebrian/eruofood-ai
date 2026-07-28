import { apiClient } from '@lib/apiClient';
import type {
  AdminAccount,
  ApprovalRequest,
  AuditEntry,
  CmsPageItem,
  FeatureFlag,
  Paginated,
  PermissionCatalogue,
  Setting,
  Ticket,
  UserSummary,
} from './types';

function query(params: Record<string, string | number | undefined>): string {
  const q = new URLSearchParams();
  for (const [k, v] of Object.entries(params)) {
    if (v !== undefined && v !== '') q.set(k, String(v));
  }
  const s = q.toString();
  return s ? `?${s}` : '';
}

/** Client for the Platform Administration REST endpoints (mounted at /admin). */
export const adminApi = {
  // RBAC
  permissions: () => apiClient.get<PermissionCatalogue>('/admin/permissions'),
  accounts: () => apiClient.get<{ accounts: AdminAccount[] }>('/admin/accounts'),
  setRoles: (userId: string, roles: string[]) =>
    apiClient.put<AdminAccount>(`/admin/accounts/${userId}/roles`, { roles }),

  // Users
  users: (q: string, status: string, page = 1) =>
    apiClient.getPage<Paginated<UserSummary>>(`/admin/users${query({ q, status, page })}`),
  suspendUser: (userId: string, reason: string) =>
    apiClient.post<{ user_id: string; status: string }>(`/admin/users/${userId}/suspend`, {
      reason,
    }),
  reinstateUser: (userId: string) =>
    apiClient.post<{ user_id: string; status: string }>(`/admin/users/${userId}/reinstate`),

  // CMS
  pages: (type: string, status: string, page = 1) =>
    apiClient.getPage<Paginated<CmsPageItem>>(`/admin/cms/pages${query({ type, status, page })}`),
  createPage: (payload: { type: string; title: string; body: string; excerpt?: string }) =>
    apiClient.post<CmsPageItem>('/admin/cms/pages', payload),
  publishPage: (id: string) => apiClient.post<CmsPageItem>(`/admin/cms/pages/${id}/publish`),
  archivePage: (id: string) => apiClient.post<CmsPageItem>(`/admin/cms/pages/${id}/archive`),

  // Config
  settings: (group: string) =>
    apiClient.get<{ settings: Setting[] }>(`/admin/settings${query({ group })}`),
  updateSetting: (key: string, value: string) =>
    apiClient.put<Setting>(`/admin/settings/${key}`, { value }),
  flags: () => apiClient.get<{ flags: FeatureFlag[] }>('/admin/flags'),
  setFlag: (key: string, enabled: boolean) =>
    apiClient.put<FeatureFlag>(`/admin/flags/${key}`, { enabled }),
  maintenance: () => apiClient.get<{ enabled: boolean; message: string | null }>('/admin/maintenance'),
  setMaintenance: (enabled: boolean, message: string) =>
    apiClient.put<{ enabled: boolean; message: string | null }>('/admin/maintenance', {
      enabled,
      message,
    }),

  // Operations
  approvals: (status: string, page = 1) =>
    apiClient.getPage<Paginated<ApprovalRequest>>(`/admin/operations/approvals${query({ status, page })}`),
  approve: (id: string, note: string) =>
    apiClient.post<ApprovalRequest>(`/admin/operations/approvals/${id}/approve`, { note }),
  reject: (id: string, note: string) =>
    apiClient.post<ApprovalRequest>(`/admin/operations/approvals/${id}/reject`, { note }),

  // Support
  tickets: (status: string, page = 1) =>
    apiClient.getPage<Paginated<Ticket>>(`/admin/support/tickets${query({ status, page })}`),
  ticket: (id: string) => apiClient.get<Ticket>(`/admin/support/tickets/${id}`),
  replyTicket: (id: string, body: string) =>
    apiClient.post<Ticket>(`/admin/support/tickets/${id}/reply`, { body }),
  resolveTicket: (id: string) => apiClient.post<Ticket>(`/admin/support/tickets/${id}/resolve`),

  // Audit
  audit: (category: string, page = 1) =>
    apiClient.getPage<Paginated<AuditEntry>>(`/admin/audit${query({ category, page })}`),
};
