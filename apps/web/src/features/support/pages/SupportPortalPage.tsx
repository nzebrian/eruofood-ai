import { useCallback, useEffect, useState } from 'react';
import { Layout } from '@shared/components/Layout';
import { Button } from '@shared/components/Button';
import { supportApi } from '../supportApi';
import { TICKET_PRIORITIES, type Ticket, type TicketSummary } from '../types';

/** The customer support portal: raise tickets, track them, reply and rate resolutions. */
export function SupportPortalPage(): React.JSX.Element {
  const [tickets, setTickets] = useState<TicketSummary[]>([]);
  const [active, setActive] = useState<Ticket | null>(null);
  const [subject, setSubject] = useState('');
  const [category, setCategory] = useState('general');
  const [priority, setPriority] = useState('normal');
  const [body, setBody] = useState('');
  const [reply, setReply] = useState('');
  const [busy, setBusy] = useState(false);

  const refresh = useCallback((): void => {
    supportApi
      .myTickets()
      .then((page) => setTickets(page.data))
      .catch(() => setTickets([]));
  }, []);

  useEffect(refresh, [refresh]);

  const open = (id: string): void => {
    supportApi
      .ticket(id)
      .then(setActive)
      .catch(() => setActive(null));
  };

  const create = (e: React.FormEvent): void => {
    e.preventDefault();
    if (subject.trim() === '' || body.trim() === '') return;
    setBusy(true);
    supportApi
      .openTicket({ subject, category, body, priority })
      .then((ticket) => {
        setSubject('');
        setBody('');
        setActive(ticket);
        refresh();
      })
      .catch(() => undefined)
      .finally(() => setBusy(false));
  };

  const sendReply = (): void => {
    if (active === null || reply.trim() === '') return;
    supportApi
      .reply(active.id, reply)
      .then((t) => {
        setActive(t);
        setReply('');
      })
      .catch(() => undefined);
  };

  const rate = (score: number): void => {
    if (active === null) return;
    supportApi
      .submitCsat(active.id, score)
      .then(() => open(active.id))
      .catch(() => undefined);
  };

  return (
    <Layout>
      <h1>Support</h1>
      <div className="support-portal">
        <section className="support-portal__side">
          <form className="support-new" onSubmit={create}>
            <h2>New ticket</h2>
            <input value={subject} onChange={(e) => setSubject(e.target.value)} placeholder="Subject" aria-label="Subject" />
            <div className="support-new__row">
              <select value={category} onChange={(e) => setCategory(e.target.value)} aria-label="Category">
                <option value="general">General</option>
                <option value="billing">Billing</option>
                <option value="orders">Orders</option>
                <option value="account">Account</option>
              </select>
              <select value={priority} onChange={(e) => setPriority(e.target.value)} aria-label="Priority">
                {TICKET_PRIORITIES.map((p) => (
                  <option key={p} value={p}>
                    {p}
                  </option>
                ))}
              </select>
            </div>
            <textarea value={body} onChange={(e) => setBody(e.target.value)} placeholder="Describe your issue" aria-label="Body" rows={4} />
            <Button type="submit" busy={busy}>
              Submit
            </Button>
          </form>

          <h2>My tickets</h2>
          <ul className="support-ticket-list">
            {tickets.length === 0 ? (
              <li className="muted">No tickets yet.</li>
            ) : (
              tickets.map((t) => (
                <li key={t.id}>
                  <button className={`support-ticket-item${active?.id === t.id ? ' is-active' : ''}`} onClick={() => open(t.id)}>
                    <span className="support-ticket-item__ref">{t.ref}</span>
                    <span className="support-ticket-item__subject">{t.subject}</span>
                    <span className={`badge badge--${t.status}`}>{t.status}</span>
                  </button>
                </li>
              ))
            )}
          </ul>
        </section>

        <section className="support-portal__detail">
          {active === null ? (
            <p className="muted">Select a ticket or create a new one.</p>
          ) : (
            <>
              <h2>
                {active.ref} · {active.subject}
              </h2>
              <p className="muted">
                <span className={`badge badge--${active.status}`}>{active.status}</span> · {active.priority} ·{' '}
                <span className={active.sla.breached ? 'sla-breached' : 'sla-ok'}>SLA {active.sla.state}</span>
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
                  <textarea value={reply} onChange={(e) => setReply(e.target.value)} placeholder="Write a reply…" aria-label="Reply" rows={3} />
                  <Button onClick={sendReply}>Reply</Button>
                </div>
              )}

              {(active.status === 'resolved' || active.status === 'closed') && active.csat_score === null && (
                <div className="support-csat">
                  <span>How did we do?</span>
                  {[1, 2, 3, 4, 5].map((n) => (
                    <button key={n} className="support-csat__star" onClick={() => rate(n)}>
                      ★{n}
                    </button>
                  ))}
                </div>
              )}
              {active.csat_score !== null && <p className="muted">Thanks for rating: {active.csat_score}/5</p>}
            </>
          )}
        </section>
      </div>
    </Layout>
  );
}
