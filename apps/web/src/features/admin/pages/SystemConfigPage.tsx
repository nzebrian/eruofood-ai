import { useState } from 'react';
import { Layout } from '@shared/components/Layout';
import { Button } from '@shared/components/Button';
import { AsyncView, EmptyState, ErrorState } from '@shared/components/StateViews';
import { describeError, useAsyncData } from '@shared/hooks/useAsyncData';
import { adminApi } from '../adminApi';
import type { FeatureFlag } from '../types';

/** System Configuration: settings, feature flags and maintenance mode. */
export function SystemConfigPage(): React.JSX.Element {
  const [drafts, setDrafts] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);
  const [actionError, setActionError] = useState<string | null>(null);

  // Maintenance mode used to start as `{ enabled: false }` and stay there when
  // the read failed, so the page asserted "Disabled" without having asked.
  // Telling an administrator the site is serving traffic when it is in
  // maintenance — or the reverse — is the kind of wrong answer that gets acted
  // on, so it is now loaded like everything else.
  const config = useAsyncData(async () => {
    const [settings, flags, maintenance] = await Promise.all([
      adminApi.settings(''),
      adminApi.flags(),
      adminApi.maintenance(),
    ]);
    return { settings: settings.settings, flags: flags.flags, maintenance };
  }, 'admin|system-config');

  const [maintMsg, setMaintMsg] = useState<string | null>(null);
  const loadedMessage =
    config.state.status === 'ready' ? (config.state.data.maintenance.message ?? '') : '';
  const message = maintMsg ?? loadedMessage;

  async function run(action: () => Promise<unknown>, failure: string): Promise<void> {
    setBusy(true);
    setActionError(null);
    try {
      await action();
      config.reload();
    } catch (err) {
      setActionError(describeError(err, failure));
    } finally {
      setBusy(false);
    }
  }

  return (
    <Layout>
      <h1>System Configuration</h1>

      {actionError !== null ? <ErrorState message={actionError} title="That did not work" /> : null}

      <AsyncView
        state={config.state}
        loadingLabel="Loading system configuration…"
        errorTitle="We could not load the system configuration"
        onRetry={config.reload}
      >
        {({ settings, flags, maintenance }) => (
          <>
            <section className="admin-panel">
              <h2>Maintenance mode</h2>
              <p className={`badge badge--${maintenance.enabled ? 'on' : 'off'}`}>
                {maintenance.enabled ? 'Enabled' : 'Disabled'}
              </p>
              <label className="field">
                <span className="field__label">Banner message shown to users</span>
                <textarea
                  className="field__input"
                  value={message}
                  onChange={(e) => setMaintMsg(e.target.value)}
                  rows={2}
                />
              </label>
              <Button
                busy={busy}
                onClick={() =>
                  void run(
                    () => adminApi.setMaintenance(!maintenance.enabled, message),
                    'Could not change maintenance mode.',
                  )
                }
              >
                {maintenance.enabled ? 'Disable' : 'Enable'} maintenance
              </Button>
            </section>

            <section className="admin-panel">
              <h2>Feature flags</h2>
              {flags.length === 0 ? (
                <EmptyState title="No feature flags are declared" />
              ) : (
                <ul className="admin-flags">
                  {flags.map((f: FeatureFlag) => (
                    <li key={f.key}>
                      <span>
                        <strong>{f.key}</strong>
                        {f.description ? <em> — {f.description}</em> : null}
                      </span>
                      <Button
                        className={`button--secondary${f.enabled ? ' is-on' : ''}`}
                        busy={busy}
                        aria-pressed={f.enabled}
                        onClick={() =>
                          void run(
                            () => adminApi.setFlag(f.key, !f.enabled),
                            `Could not change the "${f.key}" flag.`,
                          )
                        }
                      >
                        {f.enabled ? 'On' : 'Off'}
                        <span className="sr-only"> — {f.key}</span>
                      </Button>
                    </li>
                  ))}
                </ul>
              )}
            </section>

            <section className="admin-panel">
              <h2>Settings</h2>
              {settings.length === 0 ? (
                <EmptyState title="No settings are configured" />
              ) : (
                <div className="table-scroll">
                  <table className="admin-table">
                    <caption className="sr-only">Platform settings and their values</caption>
                    <thead>
                      <tr>
                        <th scope="col">Key</th>
                        <th scope="col">Group</th>
                        <th scope="col">Value</th>
                        <th scope="col">
                          <span className="sr-only">Actions</span>
                        </th>
                      </tr>
                    </thead>
                    <tbody>
                      {settings.map((s) => (
                        <tr key={s.key}>
                          <td className="break-anywhere">{s.key}</td>
                          <td>{s.group}</td>
                          <td>
                            <input
                              defaultValue={s.value}
                              disabled={s.secret}
                              aria-label={`Value for ${s.key}`}
                              onChange={(e) =>
                                setDrafts((d) => ({ ...d, [s.key]: e.target.value }))
                              }
                            />
                          </td>
                          <td>
                            <Button
                              className="button--secondary"
                              busy={busy}
                              disabled={s.secret || drafts[s.key] === undefined}
                              onClick={() => {
                                const value = drafts[s.key];
                                if (value === undefined) return;
                                void run(
                                  () => adminApi.updateSetting(s.key, value),
                                  `Could not save "${s.key}".`,
                                );
                              }}
                            >
                              Save
                              <span className="sr-only"> {s.key}</span>
                            </Button>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </section>
          </>
        )}
      </AsyncView>
    </Layout>
  );
}
