import { useState } from 'react';
import { Layout } from '@shared/components/Layout';
import { Button } from '@shared/components/Button';
import { AsyncView, EmptyState, ErrorState } from '@shared/components/StateViews';
import { describeError, useAsyncData } from '@shared/hooks/useAsyncData';
import { supportApi } from '../supportApi';
import { TICKET_STATUSES, type Ticket } from '../types';

/** The agent workspace: the live queue, ticket handling, workflow and AI assist. */
export function AgentWorkspacePage(): React.JSX.Element {
  const [status, setStatus] = useState('open');
  const [unassigned, setUnassigned] = useState(false);
  const [active, setActive] = useState<Ticket | null>(null);
  const [reply, setReply] = useState('');
  const [note, setNote] = useState('');
  const [ai, setAi] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  // `.catch(() => setQueue([]))` printed "Queue empty." for a failed fetch,
  // which on an agent's live queue reads as "there is nothing to work on".
  // Every workflow action below ended in `.catch(() => undefined)`.
  const [actionError, setActionError] = useState<string | null>(null);

  const queue = useAsyncData(
    () => supportApi.queue(status, unassigned),
    `support|queue|${status}|${String(unassigned)}`,
  );
  const refresh = queue.reload;

  const open = (id: string): void => {
    setAi(null);
    setActionError(null);
    supportApi
      .agentTicket(id)
      .then(setActive)
      .catch((err: unknown) => setActionError(describeError(err, 'Could not open that ticket.')));
  };

  const act = (fn: Promise<Ticket>, failure: string): void => {
    setBusy(true);
    setActionError(null);
    fn.then((t) => {
      setActive(t);
      refresh();
    })
      .catch((err: unknown) => setActionError(describeError(err, failure)))
      .finally(() => setBusy(false));
  };

  return (
    <Layout>
      <h1>Agent workspace</h1>

      {actionError !== null ? <ErrorState message={actionError} title="That did not work" /> : null}

      <div className="support-portal">
        <section className="support-portal__side">
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
          <label className="support-toggle">
            <input
              type="checkbox"
              checked={unassigned}
              onChange={(e) => setUnassigned(e.target.checked)}
            />{' '}
            Unassigned only
          </label>
          <AsyncView
            state={queue.state}
            loadingLabel="Loading the queue\u2026"
            errorTitle="We could not load the queue"
            onRetry={queue.reload}
          >
            {(page) =>
              page.data.length === 0 ? (
                <EmptyState
                  title="Queue empty"
                  description="No tickets match this status and filter."
                />
              ) : (
                <ul className="support-ticket-list">
                  {page.data.map((t) => (
                    <li key={t.id}>
                      <button
                        type="button"
                        className={`support-ticket-item${active?.id === t.id ? ' is-active' : ''}`}
                        aria-current={active?.id === t.id ? 'true' : undefined}
                        onClick={() => open(t.id)}
                      >
                        <span className="support-ticket-item__ref">{t.ref}</span>
                        <span className="support-ticket-item__subject">{t.subject}</span>
                        <span className={`badge badge--${t.priority}`}>{t.priority}</span>
                        {t.sla.breached ? <span className="badge badge--urgent">SLA</span> : null}
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
            <EmptyState title="Select a ticket from the queue" />
          ) : (
            <>
              <h2>
                {active.ref} · {active.subject}
              </h2>
              <p className="muted">
                <span className={`badge badge--${active.status}`}>{active.status}</span> ·{' '}
                {active.priority} ·{' '}
                <span className={active.sla.breached ? 'sla-breached' : 'sla-ok'}>
                  SLA {active.sla.state}
                </span>
              </p>

              <div className="support-actions">
                {active.assignee_id === null && (
                  <Button
                    className="button--secondary"
                    busy={busy}
                    onClick={() =>
                      act(supportApi.assign(active.id), 'Could not assign that ticket to you.')
                    }
                  >
                    Assign to me
                  </Button>
                )}
                <select
                  value={active.status}
                  onChange={(e) =>
                    act(
                      supportApi.setStatus(active.id, e.target.value),
                      'Could not change the status.',
                    )
                  }
                  aria-label="Ticket status"
                >
                  {TICKET_STATUSES.map((s) => (
                    <option key={s} value={s}>
                      {s}
                    </option>
                  ))}
                </select>
                <Button
                  className="button--secondary"
                  busy={busy}
                  onClick={() =>
                    act(supportApi.escalate(active.id), 'Could not escalate that ticket.')
                  }
                >
                  Escalate
                </Button>
                <Button
                  className="button--secondary"
                  onClick={() => {
                    setActionError(null);
                    void supportApi
                      .aiSummary(active.id)
                      .then((r) => setAi(r.summary))
                      .catch((err: unknown) =>
                        setActionError(describeError(err, 'Could not generate a summary.')),
                      );
                  }}
                >
                  AI summary
                </Button>
                <Button
                  className="button--secondary"
                  onClick={() => {
                    setActionError(null);
                    void supportApi
                      .aiSuggest(active.id)
                      .then((r) => setReply(r.suggestion))
                      .catch((err: unknown) =>
                        setActionError(describeError(err, 'Could not suggest a reply.')),
                      );
                  }}
                >
                  Suggest reply
                </Button>
              </div>

              {ai !== null && <p className="support-ai">{ai}</p>}

              <ol className="support-thread">
                {active.messages.map((m) => (
                  <li
                    key={m.id}
                    className={
                      m.internal ? 'is-internal' : m.author_type === 'agent' ? 'is-me' : ''
                    }
                  >
                    <span className="support-thread__meta">
                      {m.internal ? 'Internal note' : m.author_type} ·{' '}
                      {new Date(m.created_at).toLocaleString()}
                    </span>
                    <p>{m.body}</p>
                  </li>
                ))}
              </ol>

              <div className="support-reply">
                <textarea
                  value={reply}
                  onChange={(e) => setReply(e.target.value)}
                  placeholder="Public reply…"
                  aria-label="Reply"
                  rows={3}
                />
                <Button
                  busy={busy}
                  onClick={() => {
                    if (reply.trim() === '') return;
                    act(supportApi.agentReply(active.id, reply), 'Your reply was not sent.');
                    setReply('');
                  }}
                >
                  Reply
                </Button>
              </div>
              <div className="support-reply">
                <textarea
                  value={note}
                  onChange={(e) => setNote(e.target.value)}
                  placeholder="Internal note…"
                  aria-label="Internal note"
                  rows={2}
                />
                <Button
                  className="button--secondary"
                  busy={busy}
                  onClick={() => {
                    if (note.trim() === '') return;
                    act(supportApi.note(active.id, note), 'Your note was not saved.');
                    setNote('');
                  }}
                >
                  Add note
                </Button>
              </div>
            </>
          )}
        </section>
      </div>
    </Layout>
  );
}
