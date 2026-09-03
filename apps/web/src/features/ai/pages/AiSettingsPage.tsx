import { useState } from 'react';
import { Layout } from '@shared/components/Layout';
import { AsyncView } from '@shared/components/StateViews';
import { useAsyncData } from '@shared/hooks/useAsyncData';
import { aiApi } from '../aiApi';

/** AI settings: the caller's rolling usage & cost summary. */
export function AiSettingsPage(): React.JSX.Element {
  const [days, setDays] = useState(30);

  // "Usage is unavailable right now." was shown for every failure, with no
  // way to retry and no distinction between a server error and a window that
  // genuinely has no activity in it.
  const usage = useAsyncData(() => aiApi.usage(days), `ai|usage|${String(days)}`);

  return (
    <Layout>
      <h1>AI settings &amp; usage</h1>

      <label className="field">
        <span className="field__label">Window</span>
        <select
          className="field__input"
          value={days}
          onChange={(e) => setDays(Number(e.target.value))}
        >
          <option value={7}>Last 7 days</option>
          <option value={30}>Last 30 days</option>
          <option value={90}>Last 90 days</option>
        </select>
      </label>

      <AsyncView
        state={usage.state}
        loadingLabel="Loading your usage…"
        errorTitle="We could not load your usage"
        onRetry={usage.reload}
      >
        {(summary) => (
          <dl className="usage">
            <div>
              <dt>Requests</dt>
              <dd>{summary.requests}</dd>
            </div>
            <div>
              <dt>Cached</dt>
              <dd>{summary.cached_requests}</dd>
            </div>
            <div>
              <dt>Total tokens</dt>
              <dd>{summary.total_tokens.toLocaleString()}</dd>
            </div>
            <div>
              <dt>Estimated cost</dt>
              <dd>${summary.cost_usd.toFixed(4)}</dd>
            </div>
          </dl>
        )}
      </AsyncView>
    </Layout>
  );
}
