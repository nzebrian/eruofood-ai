import { useState } from 'react';
import { Layout } from '@shared/components/Layout';
import { Button } from '@shared/components/Button';
import { AsyncView, EmptyState, ErrorState } from '@shared/components/StateViews';
import { describeError, useAsyncData } from '@shared/hooks/useAsyncData';
import { supportApi } from '../supportApi';
import type { CustomerProfile, Interaction } from '../types';

/** The CRM + support dashboard for agents: KPIs, customer search, profile and timeline. */
export function CrmDashboardPage(): React.JSX.Element {
  const [term, setTerm] = useState('');
  const [query, setQuery] = useState('');
  const [active, setActive] = useState<CustomerProfile | null>(null);
  const [timeline, setTimeline] = useState<Interaction[]>([]);
  const [busy, setBusy] = useState(false);
  const [actionError, setActionError] = useState<string | null>(null);

  const dashboard = useAsyncData(() => supportApi.dashboard(30), 'support|crm-dashboard');
  const customers = useAsyncData(
    () => supportApi.customers(query, ''),
    `support|customers|${query}`,
  );

  const open = (userId: string): void => {
    setActionError(null);
    setTimeline([]);
    supportApi
      .customer(userId)
      .then(setActive)
      .catch((err: unknown) => setActionError(describeError(err, 'Could not open that customer.')));
    supportApi
      .timeline(userId)
      .then((page) => setTimeline(page.data))
      .catch((err: unknown) =>
        setActionError(describeError(err, 'Could not load that customer\u2019s timeline.')),
      );
  };

  const insight = (): void => {
    if (active === null) return;
    setBusy(true);
    setActionError(null);
    supportApi
      .generateInsight(active.user_id)
      .then(setActive)
      .catch((err: unknown) => setActionError(describeError(err, 'Could not generate an insight.')))
      .finally(() => setBusy(false));
  };

  return (
    <Layout>
      <h1>Support &amp; CRM</h1>

      <AsyncView
        state={dashboard.state}
        loadingLabel="Loading support metrics\u2026"
        errorTitle="We could not load the support metrics"
        onRetry={dashboard.reload}
      >
        {(metrics) => (
          <div className="bi-kpis">
            <Kpi label="Open queue" value={String(sumOpen(metrics.queue))} />
            <Kpi label="SLA breach rate" value={`${Math.round(metrics.sla.breach_rate * 100)}%`} />
            <Kpi label="Avg first response" value={`${metrics.sla.avg_first_response_minutes}m`} />
            <Kpi
              label="CSAT"
              value={metrics.csat.responses > 0 ? `${metrics.csat.average}/5` : 'No responses'}
            />
          </div>
        )}
      </AsyncView>

      {actionError !== null ? <ErrorState message={actionError} title="That did not work" /> : null}

      <div className="support-portal">
        <section className="support-portal__side">
          <form
            className="admin-filters"
            onSubmit={(e) => {
              e.preventDefault();
              setQuery(term);
            }}
          >
            <input
              value={term}
              onChange={(e) => setTerm(e.target.value)}
              placeholder="Search customers"
              aria-label="Search customers"
            />
            <Button type="submit">Search</Button>
          </form>
          <AsyncView
            state={customers.state}
            loadingLabel="Loading customers\u2026"
            errorTitle="We could not load the customer list"
            onRetry={customers.reload}
          >
            {(page) =>
              page.data.length === 0 ? (
                <EmptyState
                  title={
                    query === '' ? 'No customers yet' : `No customers match \u201C${query}\u201D`
                  }
                />
              ) : (
                <ul className="support-ticket-list">
                  {page.data.map((c) => (
                    <li key={c.user_id}>
                      <button
                        type="button"
                        className={`support-ticket-item${active?.user_id === c.user_id ? ' is-active' : ''}`}
                        aria-current={active?.user_id === c.user_id ? 'true' : undefined}
                        onClick={() => open(c.user_id)}
                      >
                        <span className="support-ticket-item__subject">
                          {c.display_name ?? c.user_id}
                        </span>
                        <span className={`badge badge--${c.segment}`}>{c.segment}</span>
                      </button>
                    </li>
                  ))}
                </ul>
              )
            }
          </AsyncView>
        </section>

        <section className="support-portal__detail">
          {active === null ? (
            <EmptyState title="Select a customer to see their profile" />
          ) : (
            <>
              <h2>{active.display_name ?? active.user_id}</h2>
              <p className="muted">
                <span className={`badge badge--${active.segment}`}>{active.segment}</span> ·{' '}
                {active.order_count} orders · ₦{(active.total_spent_minor / 100).toLocaleString()} ·{' '}
                {active.ticket_count} tickets
              </p>
              <div className="support-actions">
                <Button className="button--secondary" busy={busy} onClick={insight}>
                  Generate AI insight
                </Button>
              </div>
              {active.insight !== null && <p className="support-ai">{active.insight}</p>}

              <h3>Timeline</h3>
              {timeline.length === 0 ? (
                <EmptyState title="No interactions recorded" />
              ) : (
                <ol className="support-timeline">
                  {timeline.map((i) => (
                    <li key={i.id}>
                      <span className="support-timeline__meta">
                        {new Date(i.occurred_at).toLocaleString()}
                      </span>
                      <span>
                        <strong>{i.kind}</strong> — {i.summary}
                      </span>
                    </li>
                  ))}
                </ol>
              )}
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
