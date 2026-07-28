import { useCallback, useEffect, useState } from 'react';
import { Layout } from '@shared/components/Layout';
import { analyticsApi } from '../analyticsApi';
import { DASHBOARDS, formatKpi, type Dashboard } from '../types';

/** Interactive BI dashboard: KPI widgets, bar charts and dimension breakdowns. */
export function AnalyticsDashboardPage(): React.JSX.Element {
  const [type, setType] = useState<string>('executive');
  const [days, setDays] = useState(30);
  const [dashboard, setDashboard] = useState<Dashboard | null>(null);
  const [loading, setLoading] = useState(true);

  const refresh = useCallback((): void => {
    setLoading(true);
    analyticsApi
      .dashboard(type, days)
      .then(setDashboard)
      .catch(() => setDashboard(null))
      .finally(() => setLoading(false));
  }, [type, days]);

  useEffect(refresh, [refresh]);

  return (
    <Layout>
      <h1>Analytics</h1>

      <div className="bi-filters">
        <div className="bi-tabs">
          {DASHBOARDS.map((d) => (
            <button
              key={d}
              className={`bi-tab${d === type ? ' bi-tab--active' : ''}`}
              onClick={() => setType(d)}
            >
              {d}
            </button>
          ))}
        </div>
        <select value={days} onChange={(e) => setDays(Number(e.target.value))} aria-label="Range">
          <option value={7}>Last 7 days</option>
          <option value={30}>Last 30 days</option>
          <option value={90}>Last 90 days</option>
        </select>
      </div>

      {loading ? (
        <p className="muted">Loading…</p>
      ) : !dashboard ? (
        <p className="muted">No data.</p>
      ) : (
        <>
          <div className="bi-kpis">
            {dashboard.kpis.map((k) => (
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

          {dashboard.charts.map((chart) => (
            <BarChart key={chart.metric} label={chart.label} points={chart.points} unit={chart.unit} />
          ))}

          {Object.entries(dashboard.breakdowns).map(([name, rows]) => (
            <section key={name} className="bi-breakdown">
              <h3>{name.replace(/_/g, ' ')}</h3>
              {Object.keys(rows).length === 0 ? (
                <p className="muted">No data.</p>
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
      <h3>{label}</h3>
      {points.length === 0 ? (
        <p className="muted">No data in range.</p>
      ) : (
        <div className="bi-bars">
          {points.map((p) => (
            <div key={p.bucket} className="bi-bar" title={`${p.bucket}: ${formatKpi(p.value, unit)}`}>
              <div className="bi-bar__fill" style={{ height: `${(p.value / max) * 100}%` }} />
              <span className="bi-bar__label">{p.bucket.slice(5)}</span>
            </div>
          ))}
        </div>
      )}
    </section>
  );
}
