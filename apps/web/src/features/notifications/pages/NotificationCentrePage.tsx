import { useState } from 'react';
import { Layout } from '@shared/components/Layout';
import { Button } from '@shared/components/Button';
import { AsyncView, EmptyState, ErrorState } from '@shared/components/StateViews';
import { describeError, useAsyncData } from '@shared/hooks/useAsyncData';
import { notificationsApi } from '../notificationsApi';

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
  const [unreadOnly, setUnreadOnly] = useState(false);
  const [busy, setBusy] = useState(false);
  const [actionError, setActionError] = useState<string | null>(null);

  const notifications = useAsyncData(
    () => notificationsApi.list(unreadOnly),
    `notifications|list|${String(unreadOnly)}`,
  );

  async function run(action: () => Promise<unknown>, failure: string): Promise<void> {
    setBusy(true);
    setActionError(null);
    try {
      await action();
      notifications.reload();
    } catch (err) {
      setActionError(describeError(err, failure));
    } finally {
      setBusy(false);
    }
  }

  return (
    <Layout>
      <div className="notif-head">
        <h1>Notifications</h1>
        <Button
          className="button--secondary"
          busy={busy}
          onClick={() =>
            void run(() => notificationsApi.markAllRead(), 'Could not mark everything read.')
          }
        >
          Mark all read
        </Button>
      </div>

      <label className="notif-toggle">
        <input
          type="checkbox"
          checked={unreadOnly}
          onChange={(e) => setUnreadOnly(e.target.checked)}
        />
        Unread only
      </label>

      {actionError !== null ? <ErrorState message={actionError} title="That did not work" /> : null}

      <AsyncView
        state={notifications.state}
        loadingLabel="Loading your notifications…"
        errorTitle="We could not load your notifications"
        onRetry={notifications.reload}
      >
        {(page) =>
          page.data.length === 0 ? (
            <EmptyState
              title={unreadOnly ? 'Nothing unread' : "You're all caught up"}
              description={
                unreadOnly
                  ? 'Clear the filter to see everything you have already read.'
                  : 'Order updates, payments and reminders will arrive here.'
              }
            />
          ) : (
            <ul className="notif-list">
              {page.data.map((n) => (
                <li key={n.id} className={`notif-item${n.read ? '' : ' notif-item--unread'}`}>
                  <span className="notif-icon" aria-hidden="true">
                    {CATEGORY_ICON[n.category] ?? '🔔'}
                  </span>
                  <div className="notif-body">
                    <strong>{n.subject}</strong>
                    <span>{n.body}</span>
                    <time className="muted" dateTime={n.created_at}>
                      {new Date(n.created_at).toLocaleString()}
                    </time>
                  </div>
                  {!n.read && (
                    <button
                      type="button"
                      className="notif-dot"
                      disabled={busy}
                      onClick={() =>
                        void run(
                          () => notificationsApi.markRead(n.id),
                          'Could not mark that read.',
                        )
                      }
                      aria-label={`Mark "${n.subject}" as read`}
                    />
                  )}
                </li>
              ))}
            </ul>
          )
        }
      </AsyncView>
    </Layout>
  );
}
