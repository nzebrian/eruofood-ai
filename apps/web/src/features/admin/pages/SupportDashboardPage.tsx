import { useCallback, useEffect, useState } from 'react';
import { Layout } from '@shared/components/Layout';
import { Button } from '@shared/components/Button';
import { adminApi } from '../adminApi';
import { TICKET_STATUSES, type Ticket } from '../types';

/** Support Centre: the live ticket queue with reply and resolve. */
export function SupportDashboardPage(): React.JSX.Element {
  const [status, setStatus] = useState('open');
  const [tickets, setTickets] = useState<Ticket[]>([]);
  const [active, setActive] = useState<Ticket | null>(null);
  const [reply, setReply] = useState('');
  const [busy, setBusy] = useState(false);

  const refresh = useCallback((): void => {
    adminApi
      .tickets(status, 1)
      .then((page) => {
        setTickets(page.data);
        const first = page.data[0];
        setActive((current) => current ?? first ?? null);
      })
      .catch(() => setTickets([]));
  }, [status]);

  useEffect(refresh, [refresh]);

  const open = (ticket: Ticket): void => {
    adminApi
      .ticket(ticket.id)
      .then(setActive)
      .catch(() => setActive(ticket));
  };

  const send = (): void => {
    if (active === null || reply.trim() === '') return;
    setBusy(true);
    adminApi
      .replyTicket(active.id, reply)
      .then((t) => {
        setActive(t);
        setReply('');
      })
      .catch(() => undefined)
      .finally(() => setBusy(false));
  };

  const resolve = (): void => {
    if (active === null) return;
    adminApi
      .resolveTicket(active.id)
      .then((t) => {
        setActive(t);
        refresh();
      })
      .catch(() => undefined);
  };

  return (
    <Layout>
      <h1>Support Centre</h1>

      <div className="admin-filters">
        {TICKET_STATUSES.map((s) => (
          <button
            key={s}
            className={`bi-tab${s === status ? ' bi-tab--active' : ''}`}
            onClick={() => setStatus(s)}
          >
            {s}
          </button>
        ))}
      </div>

      <div className="support-layout">
        <ul className="support-queue">
          {tickets.length === 0 ? (
            <li className="muted">No tickets.</li>
          ) : (
            tickets.map((t) => (
              <li key={t.id}>
                <button
                  className={`support-queue__item${active?.id === t.id ? ' is-active' : ''}`}
                  onClick={() => open(t)}
                >
                  <span className="support-queue__subject">{t.subject}</span>
                  <span className={`badge badge--${t.priority}`}>{t.priority}</span>
                </button>
              </li>
            ))
          )}
        </ul>

        <section className="support-detail">
          {active === null ? (
            <p className="muted">Select a ticket.</p>
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

              <textarea
                value={reply}
                onChange={(e) => setReply(e.target.value)}
                placeholder="Write a reply…"
                aria-label="Reply"
                rows={3}
              />
              <div className="support-actions">
                <Button onClick={send} busy={busy}>
                  Reply
                </Button>
                <button className="button button--secondary" onClick={resolve}>
                  Resolve
                </button>
              </div>
            </>
          )}
        </section>
      </div>
    </Layout>
  );
}
