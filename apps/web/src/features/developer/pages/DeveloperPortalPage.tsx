import { useEffect, useState } from 'react';
import { Layout } from '@shared/components/Layout';
import { Button } from '@shared/components/Button';
import { AsyncView, EmptyState, ErrorState } from '@shared/components/StateViews';
import { describeError, useAsyncData } from '@shared/hooks/useAsyncData';
import { developerApi } from '../developerApi';
import type { ApiKey, DeveloperApplication, ScopeInfo, Usage, Webhook } from '../types';

/**
 * The developer portal: register, manage applications, issue/rotate/revoke API
 * keys, configure webhooks, and view usage. Plaintext key/secret values are
 * shown exactly once, right after they are minted.
 */
export function DeveloperPortalPage(): React.JSX.Element {
  const [scopeCatalogue, setScopeCatalogue] = useState<ScopeInfo[]>([]);
  const [selected, setSelected] = useState<DeveloperApplication | null>(null);
  const [keys, setKeys] = useState<ApiKey[]>([]);
  const [webhooks, setWebhooks] = useState<Webhook[]>([]);
  const [usage, setUsage] = useState<Usage | null>(null);
  const [oneTimeSecret, setOneTimeSecret] = useState<string | null>(null);

  const [appName, setAppName] = useState('');
  const [appScopes, setAppScopes] = useState<string[]>([]);
  const [keyName, setKeyName] = useState('');
  const [hookUrl, setHookUrl] = useState('');
  const [busy, setBusy] = useState(false);
  // Every write on this page used to end in `.catch(() => undefined)`. A
  // failed key issue, revoke or webhook registration did nothing visible at
  // all — on a screen whose whole purpose is issuing credentials.
  const [actionError, setActionError] = useState<string | null>(null);

  const apps = useAsyncData(async () => {
    // Ensure a developer account exists, then load applications.
    try {
      await developerApi.me();
    } catch {
      await developerApi.register('Developer', 'developer@example.com');
    }
    return (await developerApi.applications()).data;
  }, 'developer|applications');
  const loadApps = apps.reload;

  useEffect(() => {
    developerApi
      .scopes()
      .then((r) => setScopeCatalogue(r.scopes))
      // The scope catalogue only populates checkboxes on the create form. An
      // empty list is a visibly degraded form rather than a false statement,
      // and every action that depends on it reports its own failure.
      .catch(() => setScopeCatalogue([]));
  }, []);

  function report(failure: string): (error: unknown) => void {
    return (error: unknown) => setActionError(describeError(error, failure));
  }

  const openApp = (app: DeveloperApplication): void => {
    setSelected(app);
    setOneTimeSecret(null);
    setActionError(null);
    developerApi
      .keys(app.id)
      .then((r) => setKeys(r.keys))
      .catch(report('Could not load this application\u2019s keys.'));
    developerApi
      .webhooks(app.id)
      .then((r) => setWebhooks(r.webhooks))
      .catch(report('Could not load this application\u2019s webhooks.'));
    developerApi.usage(app.id).then(setUsage).catch(report('Could not load usage.'));
  };

  const toggleScope = (scope: string): void => {
    setAppScopes((prev) =>
      prev.includes(scope) ? prev.filter((s) => s !== scope) : [...prev, scope],
    );
  };

  const createApp = (e: React.FormEvent): void => {
    e.preventDefault();
    if (appName.trim() === '') return;
    setBusy(true);
    setActionError(null);
    developerApi
      .createApplication(appName, '', appScopes)
      .then(() => {
        setAppName('');
        setAppScopes([]);
        loadApps();
      })
      .catch(report('Could not create that application.'))
      .finally(() => setBusy(false));
  };

  const issueKey = (): void => {
    if (selected === null || keyName.trim() === '') return;
    setActionError(null);
    developerApi
      .issueKey(selected.id, keyName, selected.scopes)
      .then((issued) => {
        setOneTimeSecret(issued.key);
        setKeyName('');
        return developerApi.keys(selected.id).then((r) => setKeys(r.keys));
      })
      .catch(report('Could not issue that key.'));
  };

  const revokeKey = (keyId: string): void => {
    if (selected === null) return;
    setActionError(null);
    developerApi
      .revokeKey(keyId)
      .then(() => developerApi.keys(selected.id).then((r) => setKeys(r.keys)))
      .catch(report('Could not revoke that key.'));
  };

  const addWebhook = (): void => {
    if (selected === null || hookUrl.trim() === '') return;
    setActionError(null);
    developerApi
      .createWebhook(selected.id, hookUrl, ['review.published'])
      .then((w) => {
        setOneTimeSecret(w.secret ?? null);
        setHookUrl('');
        return developerApi.webhooks(selected.id).then((r) => setWebhooks(r.webhooks));
      })
      .catch(report('Could not register that webhook.'));
  };

  return (
    <Layout>
      <h1>Developer Platform</h1>

      {oneTimeSecret !== null && (
        <div className="dev-secret" role="alert">
          Copy this now — it will not be shown again:{' '}
          <code className="break-anywhere">{oneTimeSecret}</code>
        </div>
      )}

      {actionError !== null ? <ErrorState message={actionError} title="That did not work" /> : null}

      <div className="dev-layout">
        <section className="dev-apps">
          <h2>Applications</h2>
          <AsyncView
            state={apps.state}
            loadingLabel="Loading your applications\u2026"
            errorTitle="We could not load your applications"
            onRetry={apps.reload}
          >
            {(list) =>
              list.length === 0 ? (
                <EmptyState
                  title="No applications yet"
                  description="Register one below to issue API keys and webhooks."
                />
              ) : (
                <ul className="dev-app-list">
                  {list.map((a) => (
                    <li key={a.id}>
                      <button
                        type="button"
                        className={`dev-app-item${selected?.id === a.id ? ' is-active' : ''}`}
                        aria-current={selected?.id === a.id ? 'true' : undefined}
                        onClick={() => openApp(a)}
                      >
                        <span>{a.name}</span>
                        <span className={`badge badge--${a.status}`}>{a.status}</span>
                      </button>
                    </li>
                  ))}
                </ul>
              )
            }
          </AsyncView>

          <form className="dev-form" onSubmit={createApp}>
            <h3>New application</h3>
            <input
              value={appName}
              onChange={(e) => setAppName(e.target.value)}
              placeholder="Application name"
              aria-label="Application name"
            />
            <fieldset className="dev-scopes">
              <legend>Scopes</legend>
              {scopeCatalogue.map((s) => (
                <label key={s.scope} title={s.description}>
                  <input
                    type="checkbox"
                    checked={appScopes.includes(s.scope)}
                    onChange={() => toggleScope(s.scope)}
                  />
                  {s.scope}
                </label>
              ))}
            </fieldset>
            <Button type="submit" busy={busy}>
              Create application
            </Button>
          </form>
        </section>

        <section className="dev-detail">
          {selected === null ? (
            <p className="muted">Select an application to manage its keys and webhooks.</p>
          ) : (
            <>
              <h2>{selected.name}</h2>
              <p className="muted">Scopes: {selected.scopes.join(', ') || 'none'}</p>

              {usage !== null && (
                <p className="dev-usage">
                  Quota: {usage.quota.daily_used}/{usage.quota.daily_limit} today ·{' '}
                  {usage.quota.monthly_used}/{usage.quota.monthly_limit} this month ·{' '}
                  {usage.rate_limit.per_minute}/min
                </p>
              )}

              <h3>API keys</h3>
              <ul className="dev-key-list">
                {keys.map((k) => (
                  <li key={k.id}>
                    <code>{k.prefix}</code>
                    <span className={`badge badge--${k.status}`}>{k.status}</span>
                    {k.status === 'active' && (
                      <button className="link link--danger" onClick={() => revokeKey(k.id)}>
                        Revoke
                      </button>
                    )}
                  </li>
                ))}
              </ul>
              <div className="dev-inline-form">
                <input
                  value={keyName}
                  onChange={(e) => setKeyName(e.target.value)}
                  placeholder="Key name"
                  aria-label="Key name"
                />
                <Button onClick={issueKey}>Issue key</Button>
              </div>

              <h3>Webhooks</h3>
              <ul className="dev-key-list">
                {webhooks.map((w) => (
                  <li key={w.id}>
                    <code>{w.url}</code>
                    <span className={`badge badge--${w.status}`}>{w.status}</span>
                  </li>
                ))}
              </ul>
              <div className="dev-inline-form">
                <input
                  value={hookUrl}
                  onChange={(e) => setHookUrl(e.target.value)}
                  placeholder="https://example.com/hook"
                  aria-label="Webhook URL"
                />
                <Button onClick={addWebhook}>Add webhook</Button>
              </div>
            </>
          )}
        </section>
      </div>
    </Layout>
  );
}
