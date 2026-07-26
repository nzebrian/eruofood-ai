import { afterEach, describe, expect, it, vi } from 'vitest';
import { nutritionApi } from './nutritionApi';

describe('nutritionApi', () => {
  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('saves a health profile via PUT and unwraps the data envelope', async () => {
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(
      new Response(JSON.stringify({ data: { goal: 'maintain', weight_kg: 80 } }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      }),
    );

    const profile = await nutritionApi.saveProfile({
      weight_kg: 80,
      height_cm: 180,
      age: 30,
      gender: 'male',
      activity_level: 'moderate',
      goal: 'maintain',
    });

    expect(profile.goal).toBe('maintain');
    const [url, init] = fetchMock.mock.calls[0] ?? [];
    expect(url as string).toContain('/nutrition/profile');
    expect((init as RequestInit).method).toBe('PUT');
  });

  it('requests the diary for a specific date', async () => {
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(
      new Response(JSON.stringify({ data: { date: '2026-07-26', entries: [], totals: {}, targets: null } }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      }),
    );

    await nutritionApi.diaryDay('2026-07-26');

    expect(fetchMock.mock.calls[0]?.[0] as string).toContain('/nutrition/diary?date=2026-07-26');
  });
});
