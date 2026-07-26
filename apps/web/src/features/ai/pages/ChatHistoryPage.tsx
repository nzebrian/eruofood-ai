import { useEffect, useState } from 'react';
import { Layout } from '@shared/components/Layout';
import { aiApi } from '../aiApi';
import type { Conversation, ConversationSummary } from '../types';

/** AI chat history: browse past conversations and read a full thread. */
export function ChatHistoryPage(): React.JSX.Element {
  const [conversations, setConversations] = useState<ConversationSummary[]>([]);
  const [active, setActive] = useState<Conversation | null>(null);
  const [loading, setLoading] = useState(true);

  function refresh(): void {
    aiApi
      .conversations()
      .then((page) => setConversations(page.data))
      .catch(() => setConversations([]))
      .finally(() => setLoading(false));
  }

  useEffect(refresh, []);

  function open(id: string): void {
    aiApi
      .conversation(id)
      .then(setActive)
      .catch(() => setActive(null));
  }

  function remove(id: string): void {
    aiApi
      .deleteConversation(id)
      .then(() => {
        if (active?.id === id) setActive(null);
        refresh();
      })
      .catch(() => undefined);
  }

  return (
    <Layout>
      <h1>Chat history</h1>
      {loading ? (
        <p>Loading…</p>
      ) : conversations.length === 0 ? (
        <p>No conversations yet. Start one in the Cooking Assistant.</p>
      ) : (
        <div className="history">
          <ul className="list history__list">
            {conversations.map((c) => (
              <li key={c.id}>
                <button className="link" onClick={() => open(c.id)}>
                  {c.title}
                </button>{' '}
                <span className="muted">({c.message_count})</span>{' '}
                <button className="link link--danger" onClick={() => remove(c.id)}>
                  delete
                </button>
              </li>
            ))}
          </ul>

          {active ? (
            <div className="chat history__thread">
              <h2>{active.title}</h2>
              {active.messages.map((m, i) => (
                <div key={i} className={`chat__bubble chat__bubble--${m.role}`}>
                  {m.content}
                </div>
              ))}
            </div>
          ) : null}
        </div>
      )}
    </Layout>
  );
}
