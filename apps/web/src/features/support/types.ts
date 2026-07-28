/** Types for the Customer Support, Helpdesk & CRM module. */

export interface Paginated<T> {
  data: T[];
  meta: { page: number; per_page: number; total: number };
}

export interface SlaView {
  state: string;
  breached: boolean;
  first_response_due_at: string | null;
  resolution_due_at: string | null;
  first_response_breached: boolean;
  resolution_breached: boolean;
}

export interface TicketMessage {
  id: string;
  author_type: string;
  author_id: string | null;
  body: string;
  internal: boolean;
  created_at: string;
}

export interface Ticket {
  id: string;
  ref: string;
  requester_id: string;
  subject: string;
  category: string;
  channel: string;
  status: string;
  priority: string;
  assignee_id: string | null;
  tags: string[];
  csat_score: number | null;
  sla: SlaView;
  messages: TicketMessage[];
  created_at: string;
  updated_at: string;
}

export interface TicketSummary {
  id: string;
  ref: string;
  subject: string;
  status: string;
  priority: string;
  assignee_id: string | null;
  category: string;
  sla: SlaView;
  updated_at: string;
}

export interface Article {
  id: string;
  slug: string;
  title: string;
  body: string;
  excerpt: string | null;
  category: string;
  status: string;
  version: number;
  tags: string[];
  helpful_yes: number;
  helpful_no: number;
  updated_at: string;
}

export interface CustomerProfile {
  user_id: string;
  display_name: string | null;
  email: string | null;
  segment: string;
  order_count: number;
  total_spent_minor: number;
  ticket_count: number;
  tags: string[];
  notes: string | null;
  insight: string | null;
  last_interaction_at: string | null;
}

export interface Interaction {
  id: string;
  kind: string;
  summary: string;
  ref: string | null;
  source: string;
  occurred_at: string;
}

export interface SupportDashboard {
  queue: Record<string, number>;
  sla: {
    total: number;
    resolved: number;
    first_response_breached: number;
    resolution_breached: number;
    breach_rate: number;
    avg_first_response_minutes: number;
  };
  csat: {
    responses: number;
    average: number;
    distribution: Record<string, number>;
    satisfaction_rate: number;
  };
}

export const TICKET_STATUSES = ['new', 'open', 'pending', 'on_hold', 'resolved', 'closed'] as const;
export const TICKET_PRIORITIES = ['low', 'normal', 'high', 'urgent'] as const;
