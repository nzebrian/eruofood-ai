import { useCallback, useEffect, useState } from 'react';
import { Layout } from '@shared/components/Layout';
import { Button } from '@shared/components/Button';
import { useAuth } from '@features/auth/useAuth';
import { notificationsApi } from '../notificationsApi';
import type { Conversation, Message } from '../types';

/** A simple two-pane messaging interface: conversations + the open thread. */
export function MessagesPage(): React.JSX.Element {
  const { user } = useAuth();
  const [conversations, setConversations] = useState<Conversation[]>([]);
  const [activeId, setActiveId] = useState<string | null>(null);
  const [messages, setMessages] = useState<Message[]>([]);
  const [draft, setDraft] = useState('');

  useEffect(() => {
    notificationsApi
      .conversations()
      .then((list) => {
        setConversations(list);
        const first = list[0];
        if (first) setActiveId((current) => current ?? first.id);
      })
      .catch(() => setConversations([]));
  }, []);

  const loadMessages = useCallback((id: string): void => {
    notificationsApi
      .messages(id)
      .then((page) => setMessages([...page.data].reverse()))
      .catch(() => setMessages([]));
  }, []);

  useEffect(() => {
    if (activeId) loadMessages(activeId);
  }, [activeId, loadMessages]);

  async function send(event: React.FormEvent): Promise<void> {
    event.preventDefault();
    if (!activeId || draft.trim() === '') return;
    await notificationsApi.sendMessage(activeId, draft.trim());
    setDraft('');
    loadMessages(activeId);
  }

  return (
    <Layout>
      <h1>Messages</h1>
      <div className="chat">
        <aside className="chat-list">
          {conversations.length === 0 ? (
            <p className="muted">No conversations yet.</p>
          ) : (
            conversations.map((c) => (
              <button
                key={c.id}
                className={`chat-conv${c.id === activeId ? ' chat-conv--active' : ''}`}
                onClick={() => setActiveId(c.id)}
              >
                {c.subject ?? c.type.replace(/_/g, ' ')}
              </button>
            ))
          )}
        </aside>
        <section className="chat-thread">
          {activeId === null ? (
            <p className="muted">Select a conversation.</p>
          ) : (
            <>
              <div className="chat-messages">
                {messages.map((m) => (
                  <div
                    key={m.id}
                    className={`chat-bubble${m.sender_id === user?.id ? ' chat-bubble--me' : ''}`}
                  >
                    {m.body}
                  </div>
                ))}
              </div>
              <form onSubmit={(e) => void send(e)} className="chat-compose">
                <input
                  value={draft}
                  onChange={(e) => setDraft(e.target.value)}
                  placeholder="Type a message…"
                  aria-label="Message"
                />
                <Button type="submit">Send</Button>
              </form>
            </>
          )}
        </section>
      </div>
    </Layout>
  );
}
