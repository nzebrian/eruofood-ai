/** Types for the Notifications, Messaging & Real-Time Communication module. */

export interface Paginated<T> {
  data: T[];
  meta: { page: number; per_page: number; total: number };
}

export type NotificationCategory =
  | 'account'
  | 'order'
  | 'payment'
  | 'wallet'
  | 'delivery'
  | 'promotional'
  | 'ai'
  | 'nutrition'
  | 'admin';

export type NotificationChannel = 'email' | 'sms' | 'push' | 'in_app' | 'whatsapp' | 'telegram';

export interface AppNotification {
  id: string;
  category: NotificationCategory;
  channel: NotificationChannel;
  template_key: string;
  subject: string;
  body: string;
  priority: string;
  status: string;
  read: boolean;
  read_at: string | null;
  created_at: string;
}

export interface Preference {
  user_id: string;
  channels_by_category: Record<string, string[]>;
  quiet_hours: { enabled: boolean; start: string; end: string };
  language: string;
  max_per_day: number;
}

export interface Conversation {
  id: string;
  type: string;
  participant_ids: string[];
  subject: string | null;
  context_ref: string | null;
  last_message_at: string;
}

export interface Message {
  id: string;
  conversation_id: string;
  sender_id: string;
  type: string;
  body: string;
  read_by: string[];
  created_at: string;
}

export const CATEGORIES: NotificationCategory[] = [
  'account',
  'order',
  'payment',
  'wallet',
  'delivery',
  'promotional',
  'ai',
  'nutrition',
];

export const CHANNELS: NotificationChannel[] = ['email', 'sms', 'push', 'in_app'];
