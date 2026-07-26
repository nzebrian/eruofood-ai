import { afterEach, describe, expect, it, vi } from 'vitest';
import { aiApi } from './aiApi';

describe('aiApi', () => {
  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('posts a recipe generation request and unwraps the data envelope', async () => {
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(
      new Response(
        JSON.stringify({
          data: {
            content: { title: 'Jollof Rice', ingredients: ['rice'], steps: ['cook'] },
            meta: { provider: 'mock', model: 'mock-1', cached: false, tokens: { input: 1, output: 2, total: 3 }, finish_reason: 'stop' },
          },
        }),
        { status: 200, headers: { 'Content-Type': 'application/json' } },
      ),
    );

    const result = await aiApi.generateRecipe({ dish_name: 'Jollof Rice', servings: 4 });

    expect(result.content.title).toBe('Jollof Rice');
    expect(result.meta.provider).toBe('mock');

    const [url, init] = fetchMock.mock.calls[0] ?? [];
    expect(url as string).toContain('/ai/recipes/generate');
    expect((init as RequestInit).method).toBe('POST');
  });

  it('sends the conversation id when continuing a chat', async () => {
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(
      new Response(
        JSON.stringify({
          data: {
            conversation_id: 'c1',
            reply: 'Use a smoky base.',
            conversation: { id: 'c1', title: 't', feature: 'cooking_assistant', message_count: 2, created_at: '', updated_at: '', messages: [] },
            meta: { provider: 'mock', model: 'mock-1', cached: false, tokens: { input: 1, output: 1, total: 2 }, finish_reason: 'stop' },
          },
        }),
        { status: 200, headers: { 'Content-Type': 'application/json' } },
      ),
    );

    const turn = await aiApi.chat('and how to avoid burning?', 'c1');

    expect(turn.conversation_id).toBe('c1');
    const body = JSON.parse((fetchMock.mock.calls[0]?.[1] as RequestInit).body as string) as {
      conversation_id?: string;
    };
    expect(body.conversation_id).toBe('c1');
  });
});
