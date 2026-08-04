import { useCallback, useEffect, useState } from 'react';
import { Layout } from '@shared/components/Layout';
import { Button } from '@shared/components/Button';
import { notificationsApi } from '../notificationsApi';
import { CATEGORIES, CHANNELS, type NotificationChannel, type Preference } from '../types';

/** Notification preferences: channels per category and quiet hours. */
export function NotificationSettingsPage(): React.JSX.Element {
  const [preference, setPreference] = useState<Preference | null>(null);

  const refresh = useCallback((): void => {
    notificationsApi
      .preferences()
      .then(setPreference)
      .catch(() => setPreference(null));
  }, []);

  useEffect(refresh, [refresh]);

  function isEnabled(category: string, channel: NotificationChannel): boolean {
    const configured = preference?.channels_by_category[category];
    if (!configured) return !(category === 'promotional' && channel === 'sms');
    return configured.includes(channel);
  }

  async function toggle(category: string, channel: NotificationChannel): Promise<void> {
    if (!preference) return;
    const current = new Set(
      preference.channels_by_category[category] ??
        CHANNELS.filter((c) => isEnabled(category, c)),
    );
    if (current.has(channel)) current.delete(channel);
    else current.add(channel);
    const updated = await notificationsApi.setChannels(category, [...current] as NotificationChannel[]);
    setPreference(updated);
  }

  async function saveQuietHours(enabled: boolean): Promise<void> {
    const q = preference?.quiet_hours ?? { enabled: false, start: '22:00', end: '07:00' };
    const updated = await notificationsApi.setQuietHours(enabled, q.start, q.end);
    setPreference(updated);
  }

  if (!preference) {
    return (
      <Layout>
        <p className="muted">Loading preferences…</p>
      </Layout>
    );
  }

  return (
    <Layout>
      <h1>Notification settings</h1>

      <table className="notif-prefs">
        <thead>
          <tr>
            <th>Category</th>
            {CHANNELS.map((c) => (
              <th key={c}>{c.replace('_', '-')}</th>
            ))}
          </tr>
        </thead>
        <tbody>
          {CATEGORIES.map((cat) => (
            <tr key={cat}>
              <td>{cat}</td>
              {CHANNELS.map((ch) => (
                <td key={ch}>
                  <input
                    type="checkbox"
                    checked={isEnabled(cat, ch)}
                    disabled={ch === 'in_app'}
                    onChange={() => void toggle(cat, ch)}
                    aria-label={`${cat} ${ch}`}
                  />
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>

      <div className="notif-quiet">
        <label>
          <input
            type="checkbox"
            checked={preference.quiet_hours.enabled}
            onChange={(e) => void saveQuietHours(e.target.checked)}
          />
          Quiet hours ({preference.quiet_hours.start}–{preference.quiet_hours.end})
        </label>
        <p className="muted">Promotional &amp; reminder notifications defer during quiet hours.</p>
      </div>

      <Button className="button--secondary" onClick={refresh}>
        Refresh
      </Button>
    </Layout>
  );
}
