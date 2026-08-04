/** Types for the Platform Administration, CMS & Operations module. */

export interface Paginated<T> {
  data: T[];
  meta: { page: number; per_page: number; total: number };
}

export interface AdminAccount {
  user_id: string;
  roles: string[];
  extra_permissions: string[];
  permissions: string[];
  status: string;
  is_super: boolean;
  created_at: string;
}

export interface RoleInfo {
  value: string;
  label: string;
  permissions: string[];
}

export interface PermissionCatalogue {
  permissions: string[];
  groups: Record<string, string[]>;
  roles: RoleInfo[];
}

export interface UserSummary {
  id: string;
  name: string;
  email: string;
  status: string;
  verified: boolean;
  registered_at: string | null;
}

export interface CmsPageItem {
  id: string;
  type: string;
  slug: string;
  title: string;
  body: string;
  excerpt: string | null;
  status: string;
  author_id: string;
  published_at: string | null;
  updated_at: string;
}

export interface Setting {
  key: string;
  group: string;
  value: string;
  secret: boolean;
  description: string | null;
  updated_at: string;
}

export interface FeatureFlag {
  key: string;
  enabled: boolean;
  description: string | null;
  updated_at: string;
}

export interface ApprovalRequest {
  id: string;
  subject_type: string;
  subject_id: string;
  kind: string;
  status: string;
  note: string | null;
  submitted_at: string;
  decided_at: string | null;
}

export interface TicketMessage {
  id: string;
  author_id: string;
  body: string;
  internal: boolean;
  created_at: string;
}

export interface Ticket {
  id: string;
  requester_id: string;
  subject: string;
  category: string;
  status: string;
  priority: string;
  assignee_id: string | null;
  messages: TicketMessage[];
  created_at: string;
  updated_at: string;
}

export interface AuditEntry {
  id: string;
  actor_id: string | null;
  category: string;
  action: string;
  subject_type: string | null;
  subject_id: string | null;
  context: Record<string, unknown>;
  ip: string | null;
  created_at: string;
}

export const CONTENT_TYPES = ['page', 'blog', 'news', 'legal', 'help_article'] as const;
export const TICKET_STATUSES = ['open', 'pending', 'resolved', 'closed'] as const;
export const AUDIT_CATEGORIES = [
  'auth',
  'security',
  'config',
  'content',
  'users',
  'operations',
  'support',
  'rbac',
  'data_access',
] as const;
