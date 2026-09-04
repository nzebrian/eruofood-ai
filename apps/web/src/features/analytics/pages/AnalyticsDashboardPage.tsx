import { useState } from 'react';
import { Layout } from '@shared/components/Layout';
import { AsyncView, EmptyState } from '@shared/components/StateViews';
import { useAsyncData } from '@shared/hooks/useAsyncData';
import { analyticsApi } from '../analyticsApi';
import { DASHBOARDS, formatKpi } from '../types';

/** Interactive BI dashboard: KPI widgets, bar charts and dimension breakdowns. */
export function AnalyticsDashboardPage(): React.JSX.Element {
  const [type, setType] = useState<string>('executive');
  const [days, setDays] = useState(30);
  // `.catch(() => setDashboard(null))` rendered "No data." — which reads as a
  // finding about the business, not as a failed request.
  const dashboard = useAsyncData(
    () => analyticsApi.dashboard(type, days),
    `analytics|dashboard|${type}|${String(days)}`,
  );

  return (
    <Layout>
      <h1>Analytics</h1>

      <div className="bi-filters">
        <div className="bi-tabs">
          {DASHBOARDS.map((d) => (
            <button
              key={d}
              type="button"
              className={`bi-tab${d === type ? ' bi-tab--active' : ''}`}
              aria-pressed={d === type}
              onClick={() => setType(d)}
            >
              {d}
            </button>
          ))}
        </div>
        <select
          value={days}
          onChange={(e) => setDays(Number(e.target.value))}
          aria-label="Date range"
        >
          <option value={7}>Last 7 days</option>
          <option value={30}>Last 30 days</option>
          <option value={90}>Last 90 days</option>
        </select>
      </div>

      <AsyncView
        state={dashboard.state}
        loadingLabel="Loading the dashboard…"
        errorTitle="We could not load this dashboard"
        onRetry={dashboard.reload}
      >
        {(data) => (
          <>
            <div className="bi-kpis">
              {data.kpis.map((k) => (
                <div key={k.key} className="bi-kpi">
                  <span className="bi-kpi__label">{k.label}</span>
                  <span className="bi-kpi__value">{formatKpi(k.value, k.unit)}</span>
                  {k.delta_pct !== null && (
                    <span className={`bi-kpi__delta${k.delta_pct >= 0 ? ' up' : ' down'}`}>
                      {k.delta_pct >= 0 ? '▲' : '▼'} {Math.abs(k.delta_pct)}%
                    </span>
                  )}
                </div>
              ))}
            </div>

            {data.charts.map((chart) => (
              <BarChart
                key={chart.metric}
                label={chart.label}
                points={chart.points}
                unit={chart.unit}
              />
            ))}

            {Object.entries(data.breakdowns).map(([name, rows]) => (
              <section key={name} className="bi-breakdown">
                <h2>{name.replace(/_/g, ' ')}</h2>
                {Object.keys(rows).length === 0 ? (
                  <EmptyState title="Nothing recorded in this range" />
                ) : (
                  <ul>
                    {Object.entries(rows).map(([label, value]) => (
                      <li key={label}>
                        <span>{label}</span>
                        <strong>{value.toLocaleString()}</strong>
                      </li>
                    ))}
                  </ul>
                )}
              </section>
            ))}
          </>
        )}
      </AsyncView>
    </Layout>
  );
}

function BarChart({
  label,
  points,
  unit,
}: {
  label: string;
  points: { bucket: string; value: number }[];
  unit: string;
}): React.JSX.Element {
  const max = Math.max(1, ...points.map((p) => p.value));
  return (
    <section className="bi-chart">
      <h2>{label}</h2>
      {points.length === 0 ? (
        <EmptyState title="Nothing recorded in this range" />
      ) : (
        <div className="bi-bars">
          {points.map((p) => (
            <div
              key={p.bucket}
              className="bi-bar"
              title={`${p.bucket}: ${formatKpi(p.value, unit)}`}
            >
              <div className="bi-bar__fill" style={{ height: `${(p.value / max) * 100}%` }} />
              <span className="bi-bar__label">{p.bucket.slice(5)}</span>
            </div>
          ))}
        </div>
      )}
    </section>
  );
}
