import { useEffect, useState } from 'react';
import { Layout } from '@shared/components/Layout';
import { Button } from '@shared/components/Button';
import { AsyncView, EmptyState, ErrorState, Loading } from '@shared/components/StateViews';
import { describeError, useAsyncData } from '@shared/hooks/useAsyncData';
import { useAuth } from '@features/auth/useAuth';
import { notificationsApi } from '../notificationsApi';

/** A simple two-pane messaging interface: conversations + the open thread. */
export function MessagesPage(): React.JSX.Element {
  const { user } = useAuth();
  const [activeId, setActiveId] = useState<string | null>(null);
  const [draft, setDraft] = useState('');
  const [sending, setSending] = useState(false);
  const [sendError, setSendError] = useState<string | null>(null);

  const conversations = useAsyncData(
    () => notificationsApi.conversations(),
    'notifications|conversations',
  );

  // Open the first thread once the list arrives, without overriding a choice
  // the reader has already made.
  const firstId =
    conversations.state.status === 'ready' ? (conversations.state.data[0]?.id ?? null) : null;
  useEffect(() => {
    if (activeId === null && firstId !== null) setActiveId(firstId);
  }, [firstId, activeId]);

  const thread = useAsyncData(
    async () =>
      activeId === null ? [] : [...(await notificationsApi.messages(activeId)).data].reverse(),
    `notifications|messages|${activeId ?? ''}`,
  );

  async function send(event: React.FormEvent): Promise<void> {
    event.preventDefault();
    if (activeId === null || draft.trim() === '') return;
    setSending(true);
    setSendError(null);
    try {
      await notificationsApi.sendMessage(activeId, draft.trim());
      setDraft('');
      thread.reload();
    } catch (err) {
      // The send was previously unguarded: a failure cleared the box and lost
      // the message with nothing said. The draft is now kept.
      setSendError(describeError(err, 'Your message was not sent.'));
    } finally {
      setSending(false);
    }
  }

  return (
    <Layout>
      <h1>Messages</h1>
      <div className="chat">
        <aside className="chat-list">
          <AsyncView
            state={conversations.state}
            loadingLabel="Loading conversations…"
            errorTitle="We could not load your conversations"
            onRetry={conversations.reload}
          >
            {(list) =>
              list.length === 0 ? (
                <EmptyState
                  title="No conversations yet"
                  description="Messages about your orders and support cases will appear here."
                />
              ) : (
                <>
                  {list.map((c) => (
                    <button
                      key={c.id}
                      type="button"
                      className={`chat-conv${c.id === activeId ? ' chat-conv--active' : ''}`}
                      aria-current={c.id === activeId ? 'true' : undefined}
                      onClick={() => setActiveId(c.id)}
                    >
                      {c.subject ?? c.type.replace(/_/g, ' ')}
                    </button>
                  ))}
                </>
              )
            }
          </AsyncView>
        </aside>

        <section className="chat-thread">
          {activeId === null ? (
            <EmptyState title="Select a conversation" />
          ) : thread.state.status === 'loading' ? (
            <Loading label="Loading messages…" />
          ) : thread.state.status === 'error' ? (
            <ErrorState
              title="We could not load this conversation"
              message={thread.state.message}
              onRetry={thread.reload}
            />
          ) : (
            <>
              <div className="chat-messages">
                {thread.state.data.length === 0 ? (
                  <EmptyState title="No messages yet" description="Say hello below." />
                ) : (
                  thread.state.data.map((m) => (
                    <div
                      key={m.id}
                      className={`chat-bubble${m.sender_id === user?.id ? ' chat-bubble--me' : ''}`}
                    >
                      {m.body}
                    </div>
                  ))
                )}
              </div>
              {sendError !== null ? (
                <p className="error" role="alert">
                  {sendError}
                </p>
              ) : null}
              <form onSubmit={(e) => void send(e)} className="chat-compose">
                <input
                  value={draft}
                  onChange={(e) => setDraft(e.target.value)}
                  placeholder="Type a message…"
                  aria-label="Message"
                />
                <Button type="submit" busy={sending}>
                  Send
                </Button>
              </form>
            </>
          )}
        </section>
      </div>
    </Layout>
  );
}
