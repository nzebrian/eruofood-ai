import { useState } from 'react';
import { Link } from 'react-router-dom';
import { Layout } from '@shared/components/Layout';
import { AsyncView, EmptyState, ErrorState, Loading } from '@shared/components/StateViews';
import { describeError, useAsyncData } from '@shared/hooks/useAsyncData';
import { aiApi } from '../aiApi';
import type { Conversation } from '../types';

/** AI chat history: browse past conversations and read a full thread. */
export function ChatHistoryPage(): React.JSX.Element {
  const [active, setActive] = useState<Conversation | null>(null);
  const [threadLoading, setThreadLoading] = useState(false);
  const [actionError, setActionError] = useState<string | null>(null);

  const conversations = useAsyncData(() => aiApi.conversations(), 'ai|conversations');

  function open(id: string): void {
    setThreadLoading(true);
    setActionError(null);
    aiApi
      .conversation(id)
      .then(setActive)
      .catch((err: unknown) => {
        setActive(null);
        setActionError(describeError(err, 'Could not open that conversation.'));
      })
      .finally(() => setThreadLoading(false));
  }

  function remove(id: string): void {
    setActionError(null);
    aiApi
      .deleteConversation(id)
      .then(() => {
        if (active?.id === id) setActive(null);
        conversations.reload();
      })
      .catch((err: unknown) => {
        // Previously `.catch(() => undefined)`: a delete that failed left the
        // row on screen with no explanation at all.
        setActionError(describeError(err, 'Could not delete that conversation.'));
      });
  }

  return (
    <Layout>
      <h1>Chat history</h1>

      {actionError !== null ? <ErrorState message={actionError} title="That did not work" /> : null}

      <AsyncView
        state={conversations.state}
        loadingLabel="Loading your conversations…"
        errorTitle="We could not load your chat history"
        onRetry={conversations.reload}
      >
        {(page) =>
          page.data.length === 0 ? (
            <EmptyState
              title="No conversations yet"
              description="Ask the Cooking Assistant something and it will be saved here."
              action={
                <Link className="button button--secondary" to="/ai/assistant">
                  Open the assistant
                </Link>
              }
            />
          ) : (
            <div className="history">
              <ul className="list history__list">
                {page.data.map((c) => (
                  <li key={c.id}>
                    <button type="button" className="link" onClick={() => open(c.id)}>
                      {c.title}
                    </button>{' '}
                    <span className="muted">({c.message_count})</span>{' '}
                    <button
                      type="button"
                      className="link link--danger"
                      onClick={() => remove(c.id)}
                    >
                      Delete
                      <span className="sr-only"> conversation {c.title}</span>
                    </button>
                  </li>
                ))}
              </ul>

              {threadLoading ? (
                <div className="history__thread">
                  <Loading label="Loading conversation…" />
                </div>
              ) : active ? (
                <div className="chat history__thread">
                  <h2>{active.title}</h2>
                  {active.messages.map((m, i) => (
                    <div key={i} className={`chat__bubble chat__bubble--${m.role}`}>
                      {m.content}
                    </div>
                  ))}
                </div>
              ) : (
                <div className="history__thread">
                  <EmptyState title="Pick a conversation to read it" />
                </div>
              )}
            </div>
          )
        }
      </AsyncView>
    </Layout>
  );
}
