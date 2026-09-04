import { useState } from 'react';
import { Layout } from '@shared/components/Layout';
import { Button } from '@shared/components/Button';
import { AsyncView, ErrorState } from '@shared/components/StateViews';
import { describeError, useAsyncData } from '@shared/hooks/useAsyncData';
import { notificationsApi } from '../notificationsApi';
import { CATEGORIES, CHANNELS, type NotificationChannel, type Preference } from '../types';

/** Notification preferences: channels per category and quiet hours. */
export function NotificationSettingsPage(): React.JSX.Element {
  // `.catch(() => setPreference(null))` was also the loading condition, so a
  // failed read showed "Loading preferences…" for ever.
  const preferences = useAsyncData(() => notificationsApi.preferences(), 'notifications|prefs');

  return (
    <Layout>
      <h1>Notification settings</h1>

      <AsyncView
        state={preferences.state}
        loadingLabel="Loading your preferences…"
        errorTitle="We could not load your preferences"
        onRetry={preferences.reload}
      >
        {(preference) => (
          <PreferenceEditor
            preference={preference}
            onChange={preferences.setData}
            onReload={preferences.reload}
          />
        )}
      </AsyncView>
    </Layout>
  );
}

function PreferenceEditor({
  preference,
  onChange,
  onReload,
}: {
  preference: Preference;
  onChange: (next: Preference) => void;
  onReload: () => void;
}): React.JSX.Element {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  function isEnabled(category: string, channel: NotificationChannel): boolean {
    const configured = preference.channels_by_category[category];
    if (!configured) return !(category === 'promotional' && channel === 'sms');
    return configured.includes(channel);
  }

  async function run(action: () => Promise<Preference>, failure: string): Promise<void> {
    setBusy(true);
    setError(null);
    try {
      onChange(await action());
    } catch (err) {
      // Previously unguarded: a failed toggle left the checkbox showing the
      // new value while the server still held the old one.
      setError(describeError(err, failure));
    } finally {
      setBusy(false);
    }
  }

  function toggle(category: string, channel: NotificationChannel): void {
    const current = new Set(
      preference.channels_by_category[category] ?? CHANNELS.filter((c) => isEnabled(category, c)),
    );
    if (current.has(channel)) current.delete(channel);
    else current.add(channel);

    void run(
      () => notificationsApi.setChannels(category, [...current] as NotificationChannel[]),
      'Could not save that preference.',
    );
  }

  function saveQuietHours(enabled: boolean): void {
    const q = preference.quiet_hours;
    void run(
      () => notificationsApi.setQuietHours(enabled, q.start, q.end),
      'Could not save your quiet hours.',
    );
  }

  return (
    <>
      {error !== null ? <ErrorState message={error} title="That did not save" /> : null}

      <div className="table-scroll">
        <table className="notif-prefs">
          <caption className="sr-only">Notification channels for each category</caption>
          <thead>
            <tr>
              <th scope="col">Category</th>
              {CHANNELS.map((c) => (
                <th key={c} scope="col">
                  {c.replace('_', '-')}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {CATEGORIES.map((cat) => (
              <tr key={cat}>
                <th scope="row">{cat}</th>
                {CHANNELS.map((ch) => (
                  <td key={ch}>
                    <input
                      type="checkbox"
                      checked={isEnabled(cat, ch)}
                      disabled={ch === 'in_app' || busy}
                      onChange={() => toggle(cat, ch)}
                      aria-label={`${cat} notifications by ${ch.replace('_', '-')}`}
                    />
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <div className="notif-quiet">
        <label>
          <input
            type="checkbox"
            checked={preference.quiet_hours.enabled}
            disabled={busy}
            onChange={(e) => saveQuietHours(e.target.checked)}
          />
          Quiet hours ({preference.quiet_hours.start}–{preference.quiet_hours.end})
        </label>
        <p className="muted">Promotional &amp; reminder notifications defer during quiet hours.</p>
      </div>

      <Button className="button--secondary" busy={busy} onClick={onReload}>
        Refresh
      </Button>
    </>
  );
}
