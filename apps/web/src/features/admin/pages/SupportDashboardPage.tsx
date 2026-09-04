import { useEffect, useState } from 'react';
import { Layout } from '@shared/components/Layout';
import { Button } from '@shared/components/Button';
import { AsyncView, EmptyState, ErrorState } from '@shared/components/StateViews';
import { describeError, useAsyncData } from '@shared/hooks/useAsyncData';
import { adminApi } from '../adminApi';
import { TICKET_STATUSES, type Ticket } from '../types';

/** Support Centre: the live ticket queue with reply and resolve. */
export function SupportDashboardPage(): React.JSX.Element {
  const [status, setStatus] = useState('open');
  const [active, setActive] = useState<Ticket | null>(null);
  const [reply, setReply] = useState('');
  const [busy, setBusy] = useState(false);
  const [actionError, setActionError] = useState<string | null>(null);

  const tickets = useAsyncData(() => adminApi.tickets(status, 1), `admin|tickets|${status}`);

  // Select the first ticket once a queue arrives, without overriding a choice
  // the agent has already made.
  const firstTicket =
    tickets.state.status === 'ready' ? (tickets.state.data.data[0] ?? null) : null;
  useEffect(() => {
    if (active === null && firstTicket !== null) setActive(firstTicket);
  }, [firstTicket, active]);

  const open = (ticket: Ticket): void => {
    setActionError(null);
    adminApi
      .ticket(ticket.id)
      .then(setActive)
      .catch((err: unknown) => {
        // The summary from the queue is a reasonable fallback, but the reader
        // must be told they are looking at it rather than the full ticket.
        setActive(ticket);
        setActionError(
          describeError(err, 'Could not load the full ticket \u2014 showing the summary only.'),
        );
      });
  };

  const send = (): void => {
    if (active === null || reply.trim() === '') return;
    setBusy(true);
    setActionError(null);
    adminApi
      .replyTicket(active.id, reply)
      .then((t) => {
        setActive(t);
        setReply('');
      })
      .catch((err: unknown) => setActionError(describeError(err, 'Your reply was not sent.')))
      .finally(() => setBusy(false));
  };

  const resolve = (): void => {
    if (active === null) return;
    setBusy(true);
    setActionError(null);
    adminApi
      .resolveTicket(active.id)
      .then((t) => {
        setActive(t);
        tickets.reload();
      })
      .catch((err: unknown) => setActionError(describeError(err, 'Could not resolve that ticket.')))
      .finally(() => setBusy(false));
  };

  return (
    <Layout>
      <h1>Support Centre</h1>

      <div className="admin-filters">
        {TICKET_STATUSES.map((s) => (
          <button
            key={s}
            type="button"
            className={`bi-tab${s === status ? ' bi-tab--active' : ''}`}
            aria-pressed={s === status}
            onClick={() => setStatus(s)}
          >
            {s}
          </button>
        ))}
      </div>

      {actionError !== null ? <ErrorState message={actionError} title="That did not work" /> : null}

      <div className="support-layout">
        <AsyncView
          state={tickets.state}
          loadingLabel="Loading the ticket queue\u2026"
          errorTitle="We could not load the ticket queue"
          onRetry={tickets.reload}
        >
          {(page) =>
            page.data.length === 0 ? (
              <EmptyState title="No tickets" description={`Nothing is currently ${status}.`} />
            ) : (
              <ul className="support-queue">
                {page.data.map((t) => (
                  <li key={t.id}>
                    <button
                      type="button"
                      className={`support-queue__item${active?.id === t.id ? ' is-active' : ''}`}
                      aria-current={active?.id === t.id ? 'true' : undefined}
                      onClick={() => open(t)}
                    >
                      <span className="support-queue__subject">{t.subject}</span>
                      <span className={`badge badge--${t.priority}`}>{t.priority}</span>
                    </button>
                  </li>
                ))}
              </ul>
            )
          }
        </AsyncView>

        <section className="support-detail">
          {active === null ? (
            <EmptyState title="Select a ticket to read it" />
          ) : (
            <>
              <h2>{active.subject}</h2>
              <p className="muted">
                {active.category} · {active.status}
              </p>
              <ol className="support-thread">
                {active.messages.map((m) => (
                  <li key={m.id} className={m.internal ? 'is-internal' : ''}>
                    <span className="support-thread__meta">
                      {m.internal ? 'Internal note' : m.author_id} ·{' '}
                      {new Date(m.created_at).toLocaleString()}
                    </span>
                    <p>{m.body}</p>
                  </li>
                ))}
              </ol>

              <label className="field">
                <span className="field__label">Reply</span>
                <textarea
                  className="field__input"
                  value={reply}
                  onChange={(e) => setReply(e.target.value)}
                  placeholder="Write a reply…"
                  rows={3}
                />
              </label>
              <div className="support-actions row-actions">
                <Button onClick={send} busy={busy}>
                  Reply
                </Button>
                <Button className="button--secondary" busy={busy} onClick={resolve}>
                  Resolve
                </Button>
              </div>
            </>
          )}
        </section>
      </div>
    </Layout>
  );
}
