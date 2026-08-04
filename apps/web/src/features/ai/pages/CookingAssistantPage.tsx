import { useState } from 'react';
import { Layout } from '@shared/components/Layout';
import { Button } from '@shared/components/Button';
import { ApiRequestError } from '@lib/apiClient';
import { aiApi } from '../aiApi';
import type { ChatMessage } from '../types';

/** Smart Cooking Assistant: a multi-turn chat backed by persisted history. */
export function CookingAssistantPage(): React.JSX.Element {
  const [messages, setMessages] = useState<ChatMessage[]>([]);
  const [conversationId, setConversationId] = useState<string | undefined>(undefined);
  const [input, setInput] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function send(event: React.FormEvent): Promise<void> {
    event.preventDefault();
    const text = input.trim();
    if (text === '') return;

    setBusy(true);
    setError(null);
    const now = new Date().toISOString();
    setMessages((prev) => [...prev, { role: 'user', content: text, created_at: now }]);
    setInput('');

    try {
      const turn = await aiApi.chat(text, conversationId);
      setConversationId(turn.conversation_id);
      setMessages((prev) => [
        ...prev,
        { role: 'assistant', content: turn.reply, created_at: new Date().toISOString() },
      ]);
    } catch (err) {
      setError(err instanceof ApiRequestError ? err.error.message : 'The assistant is unavailable.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <Layout>
      <h1>Cooking Assistant</h1>
      <p>Ask about recipes, techniques, substitutions or meal ideas.</p>

      <div className="chat">
        {messages.length === 0 ? (
          <p className="chat__empty">Start the conversation — e.g. “How do I get smoky jollof?”</p>
        ) : (
          messages.map((m, i) => (
            <div key={i} className={`chat__bubble chat__bubble--${m.role}`}>
              {m.content}
            </div>
          ))
        )}
      </div>

      {error ? <p className="error">{error}</p> : null}

      <form onSubmit={(e) => void send(e)} className="chat__form">
        <input
          className="field__input"
          value={input}
          onChange={(e) => setInput(e.target.value)}
          placeholder="Type your question…"
          aria-label="Message"
        />
        <Button type="submit" busy={busy}>
          Send
        </Button>
      </form>
    </Layout>
  );
}
