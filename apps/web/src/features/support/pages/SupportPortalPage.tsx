import { useState } from 'react';
import { Layout } from '@shared/components/Layout';
import { Button } from '@shared/components/Button';
import { AsyncView, EmptyState, ErrorState } from '@shared/components/StateViews';
import { describeError, useAsyncData } from '@shared/hooks/useAsyncData';
import { supportApi } from '../supportApi';
import { TICKET_PRIORITIES, type Ticket } from '../types';

/** The customer support portal: raise tickets, track them, reply and rate resolutions. */
export function SupportPortalPage(): React.JSX.Element {
  const [active, setActive] = useState<Ticket | null>(null);
  const [subject, setSubject] = useState('');
  const [category, setCategory] = useState('general');
  const [priority, setPriority] = useState('normal');
  const [body, setBody] = useState('');
  const [reply, setReply] = useState('');
  const [busy, setBusy] = useState(false);
  // Opening a ticket, replying and rating all ended in `.catch(() => undefined)`.
  const [actionError, setActionError] = useState<string | null>(null);

  const myTickets = useAsyncData(() => supportApi.myTickets(), 'support|my-tickets');
  const refresh = myTickets.reload;

  const open = (id: string): void => {
    setActionError(null);
    supportApi
      .ticket(id)
      .then(setActive)
      .catch((err: unknown) => setActionError(describeError(err, 'Could not open that ticket.')));
  };

  const create = (e: React.FormEvent): void => {
    e.preventDefault();
    if (subject.trim() === '' || body.trim() === '') return;
    setBusy(true);
    setActionError(null);
    supportApi
      .openTicket({ subject, category, body, priority })
      .then((ticket) => {
        setSubject('');
        setBody('');
        setActive(ticket);
        refresh();
      })
      .catch((err: unknown) => setActionError(describeError(err, 'Could not open your ticket.')))
      .finally(() => setBusy(false));
  };

  const sendReply = (): void => {
    if (active === null || reply.trim() === '') return;
    setBusy(true);
    setActionError(null);
    supportApi
      .reply(active.id, reply)
      .then((t) => {
        setActive(t);
        setReply('');
      })
      .catch((err: unknown) => setActionError(describeError(err, 'Your reply was not sent.')))
      .finally(() => setBusy(false));
  };

  const rate = (score: number): void => {
    if (active === null) return;
    setActionError(null);
    supportApi
      .submitCsat(active.id, score)
      .then(() => open(active.id))
      .catch((err: unknown) => setActionError(describeError(err, 'Could not record your rating.')));
  };

  return (
    <Layout>
      <h1>Support</h1>

      {actionError !== null ? <ErrorState message={actionError} title="That did not work" /> : null}

      <div className="support-portal">
        <section className="support-portal__side">
          <form className="support-new" onSubmit={create}>
            <h2>New ticket</h2>
            <input
              value={subject}
              onChange={(e) => setSubject(e.target.value)}
              placeholder="Subject"
              aria-label="Subject"
            />
            <div className="support-new__row">
              <select
                value={category}
                onChange={(e) => setCategory(e.target.value)}
                aria-label="Category"
              >
                <option value="general">General</option>
                <option value="billing">Billing</option>
                <option value="orders">Orders</option>
                <option value="account">Account</option>
              </select>
              <select
                value={priority}
                onChange={(e) => setPriority(e.target.value)}
                aria-label="Priority"
              >
                {TICKET_PRIORITIES.map((p) => (
                  <option key={p} value={p}>
                    {p}
                  </option>
                ))}
              </select>
            </div>
            <textarea
              value={body}
              onChange={(e) => setBody(e.target.value)}
              placeholder="Describe your issue"
              aria-label="Body"
              rows={4}
            />
            <Button type="submit" busy={busy}>
              Submit
            </Button>
          </form>

          <h2>My tickets</h2>
          <AsyncView
            state={myTickets.state}
            loadingLabel="Loading your tickets\u2026"
            errorTitle="We could not load your tickets"
            onRetry={myTickets.reload}
          >
            {(page) =>
              page.data.length === 0 ? (
                <EmptyState
                  title="No tickets yet"
                  description="Raise one with the form above and we will get back to you."
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
                        <span className={`badge badge--${t.status}`}>{t.status}</span>
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
            <EmptyState title="Select a ticket, or create a new one" />
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
              <ol className="support-thread">
                {active.messages.map((m) => (
                  <li key={m.id} className={m.author_type === 'customer' ? 'is-me' : ''}>
                    <span className="support-thread__meta">
                      {m.author_type} · {new Date(m.created_at).toLocaleString()}
                    </span>
                    <p>{m.body}</p>
                  </li>
                ))}
              </ol>

              {active.status !== 'closed' && (
                <div className="support-reply">
                  <textarea
                    value={reply}
                    onChange={(e) => setReply(e.target.value)}
                    placeholder="Write a reply…"
                    aria-label="Reply"
                    rows={3}
                  />
                  <Button busy={busy} onClick={sendReply}>
                    Reply
                  </Button>
                </div>
              )}

              {(active.status === 'resolved' || active.status === 'closed') &&
                active.csat_score === null && (
                  <div className="support-csat">
                    <span>How did we do?</span>
                    {[1, 2, 3, 4, 5].map((n) => (
                      <button
                        key={n}
                        type="button"
                        className="support-csat__star"
                        onClick={() => rate(n)}
                        aria-label={`Rate ${String(n)} out of 5`}
                      >
                        ★{n}
                      </button>
                    ))}
                  </div>
                )}
              {active.csat_score !== null && (
                <p className="muted">Thanks for rating: {active.csat_score}/5</p>
              )}
            </>
          )}
        </section>
      </div>
    </Layout>
  );
}
