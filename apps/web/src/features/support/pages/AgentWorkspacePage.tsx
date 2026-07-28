import { useCallback, useEffect, useState } from 'react';
import { Layout } from '@shared/components/Layout';
import { Button } from '@shared/components/Button';
import { supportApi } from '../supportApi';
import { TICKET_STATUSES, type Ticket, type TicketSummary } from '../types';

/** The agent workspace: the live queue, ticket handling, workflow and AI assist. */
export function AgentWorkspacePage(): React.JSX.Element {
  const [status, setStatus] = useState('open');
  const [unassigned, setUnassigned] = useState(false);
  const [queue, setQueue] = useState<TicketSummary[]>([]);
  const [active, setActive] = useState<Ticket | null>(null);
  const [reply, setReply] = useState('');
  const [note, setNote] = useState('');
  const [ai, setAi] = useState<string | null>(null);

  const refresh = useCallback((): void => {
    supportApi
      .queue(status, unassigned)
      .then((page) => setQueue(page.data))
      .catch(() => setQueue([]));
  }, [status, unassigned]);

  useEffect(refresh, [refresh]);

  const open = (id: string): void => {
    setAi(null);
    supportApi
      .agentTicket(id)
      .then(setActive)
      .catch(() => setActive(null));
  };

  const act = (fn: Promise<Ticket>): void => {
    fn.then((t) => {
      setActive(t);
      refresh();
    }).catch(() => undefined);
  };

  return (
    <Layout>
      <h1>Agent workspace</h1>
      <div className="support-portal">
        <section className="support-portal__side">
          <div className="admin-filters">
            {TICKET_STATUSES.map((s) => (
              <button key={s} className={`bi-tab${s === status ? ' bi-tab--active' : ''}`} onClick={() => setStatus(s)}>
                {s}
              </button>
            ))}
          </div>
          <label className="support-toggle">
            <input type="checkbox" checked={unassigned} onChange={(e) => setUnassigned(e.target.checked)} /> Unassigned only
          </label>
          <ul className="support-ticket-list">
            {queue.length === 0 ? (
              <li className="muted">Queue empty.</li>
            ) : (
              queue.map((t) => (
                <li key={t.id}>
                  <button className={`support-ticket-item${active?.id === t.id ? ' is-active' : ''}`} onClick={() => open(t.id)}>
                    <span className="support-ticket-item__ref">{t.ref}</span>
                    <span className="support-ticket-item__subject">{t.subject}</span>
                    <span className={`badge badge--${t.priority}`}>{t.priority}</span>
                    {t.sla.breached ? <span className="badge badge--urgent">SLA</span> : null}
                  </button>
                </li>
              ))
            )}
          </ul>
        </section>

        <section className="support-portal__detail">
          {active === null ? (
            <p className="muted">Select a ticket from the queue.</p>
          ) : (
            <>
              <h2>
                {active.ref} · {active.subject}
              </h2>
              <p className="muted">
                <span className={`badge badge--${active.status}`}>{active.status}</span> · {active.priority} ·{' '}
                <span className={active.sla.breached ? 'sla-breached' : 'sla-ok'}>SLA {active.sla.state}</span>
              </p>

              <div className="support-actions">
                {active.assignee_id === null && (
                  <button className="button button--secondary" onClick={() => act(supportApi.assign(active.id))}>
                    Assign to me
                  </button>
                )}
                <select value={active.status} onChange={(e) => act(supportApi.setStatus(active.id, e.target.value))} aria-label="Status">
                  {TICKET_STATUSES.map((s) => (
                    <option key={s} value={s}>
                      {s}
                    </option>
                  ))}
                </select>
                <button className="button button--secondary" onClick={() => act(supportApi.escalate(active.id))}>
                  Escalate
                </button>
                <button
                  className="button button--secondary"
                  onClick={() => {
                    void supportApi.aiSummary(active.id).then((r) => setAi(r.summary)).catch(() => undefined);
                  }}
                >
                  AI summary
                </button>
                <button
                  className="button button--secondary"
                  onClick={() => {
                    void supportApi.aiSuggest(active.id).then((r) => setReply(r.suggestion)).catch(() => undefined);
                  }}
                >
                  Suggest reply
                </button>
              </div>

              {ai !== null && <p className="support-ai">{ai}</p>}

              <ol className="support-thread">
                {active.messages.map((m) => (
                  <li key={m.id} className={m.internal ? 'is-internal' : m.author_type === 'agent' ? 'is-me' : ''}>
                    <span className="support-thread__meta">
                      {m.internal ? 'Internal note' : m.author_type} · {new Date(m.created_at).toLocaleString()}
                    </span>
                    <p>{m.body}</p>
                  </li>
                ))}
              </ol>

              <div className="support-reply">
                <textarea value={reply} onChange={(e) => setReply(e.target.value)} placeholder="Public reply…" aria-label="Reply" rows={3} />
                <Button
                  onClick={() => {
                    if (reply.trim() === '') return;
                    act(supportApi.agentReply(active.id, reply));
                    setReply('');
                  }}
                >
                  Reply
                </Button>
              </div>
              <div className="support-reply">
                <textarea value={note} onChange={(e) => setNote(e.target.value)} placeholder="Internal note…" aria-label="Internal note" rows={2} />
                <button
                  className="button button--secondary"
                  onClick={() => {
                    if (note.trim() === '') return;
                    act(supportApi.note(active.id, note));
                    setNote('');
                  }}
                >
                  Add note
                </button>
              </div>
            </>
          )}
        </section>
      </div>
    </Layout>
  );
}
