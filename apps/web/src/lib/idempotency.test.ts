import { afterEach, describe, expect, it, vi } from 'vitest';
import { IDEMPOTENCY_HEADER, newIdempotencyKey } from './idempotency';

/**
 * M43 — the key generator itself.
 *
 * The interesting property is not "it returns a string" but that it returns a
 * *different* string every time from a *cryptographic* source. A generator that
 * quietly fell back to a counter or a timestamp would satisfy every other test
 * in this milestone while removing the protection entirely.
 */
describe('newIdempotencyKey', () => {
  afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
  });

  it('uses the platform UUID generator when it is available', () => {
    const randomUUID = vi.fn(() => '11111111-1111-4111-8111-111111111111');
    vi.stubGlobal('crypto', { randomUUID, getRandomValues: vi.fn() });

    expect(newIdempotencyKey()).toBe('11111111-1111-4111-8111-111111111111');
    expect(randomUUID).toHaveBeenCalledTimes(1);
  });

  it('produces a distinct key on every call', () => {
    const keys = new Set(Array.from({ length: 500 }, () => newIdempotencyKey()));

    expect(keys.size).toBe(500);
  });

  it('falls back to getRandomValues outside a secure context, and still emits a v4 UUID', () => {
    // `crypto.randomUUID` is secure-context only, so a build served over plain
    // HTTP does not have it. `getRandomValues` is not gated the same way.
    let counter = 0;
    vi.stubGlobal('crypto', {
      getRandomValues: (array: Uint8Array) => {
        for (let i = 0; i < array.length; i++) array[i] = (counter + i) % 256;
        counter += 16;
        return array;
      },
    });

    const key = newIdempotencyKey();

    expect(key).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
    expect(newIdempotencyKey()).not.toBe(key);
  });

  it('throws rather than emitting a guessable key when no crypto source exists', () => {
    // The fail-closed direction. `Math.random()` here would look like
    // protection and would not be: a predictable value can collide with the
    // caller's own next operation and have it refused as a key reuse.
    vi.stubGlobal('crypto', undefined);

    expect(() => newIdempotencyKey()).toThrow(/cryptographic random source/i);
  });

  it('does not derive the key from any business value', () => {
    // Guards the rule directly: the generator takes no arguments, so it cannot
    // be fed an order id, an amount or a user id even by accident.
    expect(newIdempotencyKey.length).toBe(0);
  });

  it('names the header exactly as the API reads it', () => {
    expect(IDEMPOTENCY_HEADER).toBe('Idempotency-Key');
  });
});
