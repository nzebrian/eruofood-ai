import { useCallback, useEffect, useState } from 'react';
import { Layout } from '@shared/components/Layout';
import { Button } from '@shared/components/Button';
import { adminApi } from '../adminApi';
import type { FeatureFlag, Setting } from '../types';

/** System Configuration: settings, feature flags and maintenance mode. */
export function SystemConfigPage(): React.JSX.Element {
  const [settings, setSettings] = useState<Setting[]>([]);
  const [flags, setFlags] = useState<FeatureFlag[]>([]);
  const [maintenance, setMaintenance] = useState<{ enabled: boolean; message: string | null }>({
    enabled: false,
    message: null,
  });
  const [drafts, setDrafts] = useState<Record<string, string>>({});
  const [maintMsg, setMaintMsg] = useState('');

  const refresh = useCallback((): void => {
    adminApi
      .settings('')
      .then((r) => setSettings(r.settings))
      .catch(() => setSettings([]));
    adminApi
      .flags()
      .then((r) => setFlags(r.flags))
      .catch(() => setFlags([]));
    adminApi
      .maintenance()
      .then((m) => {
        setMaintenance(m);
        setMaintMsg(m.message ?? '');
      })
      .catch(() => undefined);
  }, []);

  useEffect(refresh, [refresh]);

  const saveSetting = (key: string): void => {
    const value = drafts[key];
    if (value === undefined) return;
    adminApi
      .updateSetting(key, value)
      .then(refresh)
      .catch(() => undefined);
  };

  const toggleFlag = (flag: FeatureFlag): void => {
    adminApi
      .setFlag(flag.key, !flag.enabled)
      .then(refresh)
      .catch(() => undefined);
  };

  const toggleMaintenance = (): void => {
    adminApi
      .setMaintenance(!maintenance.enabled, maintMsg)
      .then((m) => setMaintenance(m))
      .catch(() => undefined);
  };

  return (
    <Layout>
      <h1>System Configuration</h1>

      <section className="admin-panel">
        <h2>Maintenance mode</h2>
        <p className={`badge badge--${maintenance.enabled ? 'on' : 'off'}`}>
          {maintenance.enabled ? 'Enabled' : 'Disabled'}
        </p>
        <textarea
          value={maintMsg}
          onChange={(e) => setMaintMsg(e.target.value)}
          placeholder="Banner message shown to users"
          aria-label="Maintenance message"
          rows={2}
        />
        <Button onClick={toggleMaintenance}>
          {maintenance.enabled ? 'Disable' : 'Enable'} maintenance
        </Button>
      </section>

      <section className="admin-panel">
        <h2>Feature flags</h2>
        <ul className="admin-flags">
          {flags.map((f) => (
            <li key={f.key}>
              <span>
                <strong>{f.key}</strong>
                {f.description ? <em> — {f.description}</em> : null}
              </span>
              <button
                className={`button button--secondary${f.enabled ? ' is-on' : ''}`}
                onClick={() => toggleFlag(f)}
              >
                {f.enabled ? 'On' : 'Off'}
              </button>
            </li>
          ))}
        </ul>
      </section>

      <section className="admin-panel">
        <h2>Settings</h2>
        <table className="admin-table">
          <thead>
            <tr>
              <th>Key</th>
              <th>Group</th>
              <th>Value</th>
              <th />
            </tr>
          </thead>
          <tbody>
            {settings.map((s) => (
              <tr key={s.key}>
                <td>{s.key}</td>
                <td>{s.group}</td>
                <td>
                  <input
                    defaultValue={s.value}
                    disabled={s.secret}
                    aria-label={`Value for ${s.key}`}
                    onChange={(e) => setDrafts((d) => ({ ...d, [s.key]: e.target.value }))}
                  />
                </td>
                <td>
                  <button
                    className="button button--secondary"
                    onClick={() => saveSetting(s.key)}
                    disabled={s.secret || drafts[s.key] === undefined}
                  >
                    Save
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </section>
    </Layout>
  );
}
