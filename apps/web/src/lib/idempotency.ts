/**
 * Idempotency keys for money-moving requests (M43).
 *
 * ## What this is for
 *
 * The API has accepted `Idempotency-Key` on every money-moving endpoint since
 * M23, and M41 made it mandatory on subscriptions. This client sent it on
 * nothing. A duplicate submission — a double-click, a browser resend, a flaky
 * connection — therefore created a second order, a second payment intent or a
 * second wallet movement, and the customer saw two charges.
 *
 * The server is authoritative and unchanged: it hashes the key together with
 * the authenticated principal, so two people may present the same value without
 * colliding, and it decides what a repeat means. This module's only job is to
 * mint a value the server can key on.
 *
 * ## Why the value must be random
 *
 * A key derived from business data — an order id, an amount, a timestamp — is
 * guessable and, worse, *collidable*: two genuinely different operations by the
 * same person can produce the same derived value, at which point the server
 * correctly refuses the second one as a key reuse. Randomness is what makes
 * "same key" mean "same operation" rather than "similar operation".
 */

/** The header the API reads. Spelled once, so no caller can typo it. */
export const IDEMPOTENCY_HEADER = 'Idempotency-Key';

/**
 * A fresh key for one logical money-moving operation.
 *
 * `crypto.randomUUID()` is the first choice but is only defined in a secure
 * context, so a build served over plain HTTP on a LAN address does not have it
 * — while `crypto.getRandomValues()`, which is not secure-context gated, is
 * still there. The fallback formats those bytes as a v4 UUID.
 *
 * If neither exists this throws rather than reaching for `Math.random()`. A
 * predictable key looks like protection and is not: it can collide with the
 * caller's own next operation and have it refused as a reuse. Failing the
 * request loudly is the safer of the two bad outcomes, and no supported target
 * reaches it.
 *
 * @throws Error when the platform offers no cryptographic random source
 */
export function newIdempotencyKey(): string {
  const source = globalThis.crypto;

  if (typeof source?.randomUUID === 'function') {
    return source.randomUUID();
  }

  if (typeof source?.getRandomValues === 'function') {
    const bytes = source.getRandomValues(new Uint8Array(16));

    // RFC 4122 §4.4: version 4 in the high nibble of byte 6, variant 10 in the
    // top bits of byte 8. The `?? 0` satisfies `noUncheckedIndexedAccess` on a
    // fixed-length array where neither index can actually be absent.
    bytes[6] = ((bytes[6] ?? 0) & 0x0f) | 0x40;
    bytes[8] = ((bytes[8] ?? 0) & 0x3f) | 0x80;

    const hex = Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');

    return [
      hex.slice(0, 8),
      hex.slice(8, 12),
      hex.slice(12, 16),
      hex.slice(16, 20),
      hex.slice(20),
    ].join('-');
  }

  throw new Error(
    'No cryptographic random source is available, so a money-moving request cannot be made idempotent.',
  );
}
