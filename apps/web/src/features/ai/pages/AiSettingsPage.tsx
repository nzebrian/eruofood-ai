import { useEffect, useState } from 'react';
import { Layout } from '@shared/components/Layout';
import { aiApi } from '../aiApi';
import type { UsageSummary } from '../types';

/** AI settings: the caller's rolling usage & cost summary. */
export function AiSettingsPage(): React.JSX.Element {
  const [days, setDays] = useState(30);
  const [usage, setUsage] = useState<UsageSummary | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    setLoading(true);
    aiApi
      .usage(days)
      .then(setUsage)
      .catch(() => setUsage(null))
      .finally(() => setLoading(false));
  }, [days]);

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

      {loading ? (
        <p>Loading…</p>
      ) : usage ? (
        <dl className="usage">
          <div>
            <dt>Requests</dt>
            <dd>{usage.requests}</dd>
          </div>
          <div>
            <dt>Cached</dt>
            <dd>{usage.cached_requests}</dd>
          </div>
          <div>
            <dt>Total tokens</dt>
            <dd>{usage.total_tokens.toLocaleString()}</dd>
          </div>
          <div>
            <dt>Estimated cost</dt>
            <dd>${usage.cost_usd.toFixed(4)}</dd>
          </div>
        </dl>
      ) : (
        <p>Usage is unavailable right now.</p>
      )}
    </Layout>
  );
}
