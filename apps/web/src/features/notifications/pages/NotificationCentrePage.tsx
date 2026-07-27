import { useCallback, useEffect, useState } from 'react';
import { Layout } from '@shared/components/Layout';
import { Button } from '@shared/components/Button';
import { notificationsApi } from '../notificationsApi';
import type { AppNotification } from '../types';

const CATEGORY_ICON: Record<string, string> = {
  account: '👤',
  order: '🛍️',
  payment: '💳',
  wallet: '👛',
  delivery: '🛵',
  promotional: '🎉',
  ai: '✨',
  nutrition: '🥗',
  admin: '📢',
};

/** The in-app notification centre. */
export function NotificationCentrePage(): React.JSX.Element {
  const [notifications, setNotifications] = useState<AppNotification[]>([]);
  const [unreadOnly, setUnreadOnly] = useState(false);
  const [loading, setLoading] = useState(true);

  const refresh = useCallback((): void => {
    setLoading(true);
    notificationsApi
      .list(unreadOnly)
      .then((page) => setNotifications(page.data))
      .catch(() => setNotifications([]))
      .finally(() => setLoading(false));
  }, [unreadOnly]);

  useEffect(refresh, [refresh]);

  async function markRead(id: string): Promise<void> {
    await notificationsApi.markRead(id);
    refresh();
  }

  async function markAll(): Promise<void> {
    await notificationsApi.markAllRead();
    refresh();
  }

  return (
    <Layout>
      <div className="notif-head">
        <h1>Notifications</h1>
        <Button className="button--secondary" onClick={() => void markAll()}>
          Mark all read
        </Button>
      </div>

      <label className="notif-toggle">
        <input type="checkbox" checked={unreadOnly} onChange={(e) => setUnreadOnly(e.target.checked)} />
        Unread only
      </label>

      {loading ? (
        <p className="muted">Loading…</p>
      ) : notifications.length === 0 ? (
        <p className="muted">You&apos;re all caught up.</p>
      ) : (
        <ul className="notif-list">
          {notifications.map((n) => (
            <li key={n.id} className={`notif-item${n.read ? '' : ' notif-item--unread'}`}>
              <span className="notif-icon" aria-hidden>
                {CATEGORY_ICON[n.category] ?? '🔔'}
              </span>
              <div className="notif-body">
                <strong>{n.subject}</strong>
                <span>{n.body}</span>
                <time className="muted">{new Date(n.created_at).toLocaleString()}</time>
              </div>
              {!n.read && (
                <button className="notif-dot" onClick={() => void markRead(n.id)} aria-label="Mark read" />
              )}
            </li>
          ))}
        </ul>
      )}
    </Layout>
  );
}
