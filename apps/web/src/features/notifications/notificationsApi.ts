import { apiClient } from '@lib/apiClient';
import type {
  AppNotification,
  Conversation,
  Message,
  NotificationChannel,
  Paginated,
  Preference,
} from './types';

/** Client for the Notifications REST endpoints (mounted at /notifications). */
export const notificationsApi = {
  list: (unread = false) =>
    apiClient.getPage<Paginated<AppNotification>>(`/notifications${unread ? '?unread=1' : ''}`),
  unreadCount: () => apiClient.get<{ unread: number }>('/notifications/unread-count'),
  markRead: (id: string) => apiClient.post<AppNotification>(`/notifications/${id}/read`),
  markAllRead: () => apiClient.post<void>('/notifications/read-all'),

  preferences: () => apiClient.get<Preference>('/notifications/preferences'),
  setChannels: (category: string, channels: NotificationChannel[]) =>
    apiClient.put<Preference>('/notifications/preferences/channels', { category, channels }),
  setQuietHours: (enabled: boolean, start: string, end: string) =>
    apiClient.put<Preference>('/notifications/preferences/quiet-hours', { enabled, start, end }),

  conversations: () => apiClient.get<Conversation[]>('/notifications/conversations'),
  messages: (id: string) =>
    apiClient.getPage<Paginated<Message>>(`/notifications/conversations/${id}/messages`),
  sendMessage: (id: string, body: string) =>
    apiClient.post<Message>(`/notifications/conversations/${id}/messages`, { body }),
  startConversation: (type: string, participantIds: string[], subject?: string) =>
    apiClient.post<Conversation>('/notifications/conversations', {
      type,
      participant_ids: participantIds,
      subject,
    }),
};
