import { useCallback, useEffect, useState } from 'react';
import { Layout } from '@shared/components/Layout';
import { Button } from '@shared/components/Button';
import { supportApi } from '../supportApi';
import type { CustomerProfile, Interaction, SupportDashboard } from '../types';

/** The CRM + support dashboard for agents: KPIs, customer search, profile and timeline. */
export function CrmDashboardPage(): React.JSX.Element {
  const [dashboard, setDashboard] = useState<SupportDashboard | null>(null);
  const [term, setTerm] = useState('');
  const [customers, setCustomers] = useState<CustomerProfile[]>([]);
  const [active, setActive] = useState<CustomerProfile | null>(null);
  const [timeline, setTimeline] = useState<Interaction[]>([]);

  useEffect(() => {
    supportApi
      .dashboard(30)
      .then(setDashboard)
      .catch(() => setDashboard(null));
  }, []);

  const search = useCallback((): void => {
    supportApi
      .customers(term, '')
      .then((page) => setCustomers(page.data))
      .catch(() => setCustomers([]));
  }, [term]);

  const open = (userId: string): void => {
    supportApi
      .customer(userId)
      .then(setActive)
      .catch(() => setActive(null));
    supportApi
      .timeline(userId)
      .then((page) => setTimeline(page.data))
      .catch(() => setTimeline([]));
  };

  const insight = (): void => {
    if (active === null) return;
    supportApi
      .generateInsight(active.user_id)
      .then(setActive)
      .catch(() => undefined);
  };

  return (
    <Layout>
      <h1>Support &amp; CRM</h1>

      {dashboard !== null && (
        <div className="bi-kpis">
          <Kpi label="Open queue" value={String(sumOpen(dashboard.queue))} />
          <Kpi label="SLA breach rate" value={`${Math.round(dashboard.sla.breach_rate * 100)}%`} />
          <Kpi label="Avg first response" value={`${dashboard.sla.avg_first_response_minutes}m`} />
          <Kpi label="CSAT" value={dashboard.csat.responses > 0 ? `${dashboard.csat.average}/5` : '—'} />
        </div>
      )}

      <div className="support-portal">
        <section className="support-portal__side">
          <form
            className="admin-filters"
            onSubmit={(e) => {
              e.preventDefault();
              search();
            }}
          >
            <input value={term} onChange={(e) => setTerm(e.target.value)} placeholder="Search customers" aria-label="Search customers" />
            <Button type="submit">Search</Button>
          </form>
          <ul className="support-ticket-list">
            {customers.length === 0 ? (
              <li className="muted">No customers.</li>
            ) : (
              customers.map((c) => (
                <li key={c.user_id}>
                  <button className={`support-ticket-item${active?.user_id === c.user_id ? ' is-active' : ''}`} onClick={() => open(c.user_id)}>
                    <span className="support-ticket-item__subject">{c.display_name ?? c.user_id}</span>
                    <span className={`badge badge--${c.segment}`}>{c.segment}</span>
                  </button>
                </li>
              ))
            )}
          </ul>
        </section>

        <section className="support-portal__detail">
          {active === null ? (
            <p className="muted">Select a customer.</p>
          ) : (
            <>
              <h2>{active.display_name ?? active.user_id}</h2>
              <p className="muted">
                <span className={`badge badge--${active.segment}`}>{active.segment}</span> · {active.order_count} orders · ₦
                {(active.total_spent_minor / 100).toLocaleString()} · {active.ticket_count} tickets
              </p>
              <div className="support-actions">
                <button className="button button--secondary" onClick={insight}>
                  Generate AI insight
                </button>
              </div>
              {active.insight !== null && <p className="support-ai">{active.insight}</p>}

              <h3>Timeline</h3>
              <ol className="support-timeline">
                {timeline.length === 0 ? (
                  <li className="muted">No interactions.</li>
                ) : (
                  timeline.map((i) => (
                    <li key={i.id}>
                      <span className="support-timeline__meta">{new Date(i.occurred_at).toLocaleString()}</span>
                      <span>
                        <strong>{i.kind}</strong> — {i.summary}
                      </span>
                    </li>
                  ))
                )}
              </ol>
            </>
          )}
        </section>
      </div>
    </Layout>
  );
}

function Kpi({ label, value }: { label: string; value: string }): React.JSX.Element {
  return (
    <div className="bi-kpi">
      <span className="bi-kpi__label">{label}</span>
      <span className="bi-kpi__value">{value}</span>
    </div>
  );
}

function sumOpen(queue: Record<string, number>): number {
  return ['new', 'open', 'pending', 'on_hold'].reduce((total, key) => total + (queue[key] ?? 0), 0);
}
