<?php

declare(strict_types=1);

namespace EruoFood\Shared\Interface\Http\Concerns;

use EruoFood\Shared\Domain\Exception\InvalidArgumentException;
use Illuminate\Http\Request;

/**
 * Reads the client's `Idempotency-Key` header and fingerprints the request body.
 *
 * The header is optional — a caller that omits it simply gets the old
 * at-least-once behaviour — but any client retrying a money-moving call should
 * send one. The fingerprint lets the store tell a genuine retry (same key, same
 * body → replay the stored response) from a client bug (same key, different body
 * → refuse), which a key alone cannot distinguish.
 */
trait UsesIdempotencyKey
{
    /**
     * The longest key we will accept, matching the storage column.
     *
     * {@see idempotencyKey()} truncates at this length; {@see
     * principalScopedIdempotencyKey()} refuses instead. See that method for why
     * the two differ.
     */
    private const MAX_IDEMPOTENCY_KEY_LENGTH = 255;

    /** The client-supplied key, or null when the request is not idempotent. */
    protected function idempotencyKey(Request $request): ?string
    {
        $key = $request->header('Idempotency-Key');

        if (! is_string($key)) {
            return null;
        }

        $key = trim($key);

        // Bound the length so a hostile client cannot use the key column as
        // storage, and ignore an empty header rather than keying on "".
        return $key === '' ? null : mb_substr($key, 0, self::MAX_IDEMPOTENCY_KEY_LENGTH);
    }

    /**
     * The key to claim, bound to the authenticated principal.
     *
     * ## Why the key is derived rather than used as sent
     *
     * The store's mutex is `unique(scope, idempotency_key)` — no principal. Two
     * different users who happen to pick the same key value therefore collide:
     * the second is refused as a reused key even though the two requests have
     * nothing to do with each other. Callers before M41 papered over this by
     * putting the actor in the *fingerprint*, which stops a cross-user replay
     * but leaves the collision.
     *
     * Binding the principal into the key value fixes both at once. The database
     * constraint becomes per-principal without touching the index — which
     * matters, because widening the index to include the nullable `user_id`
     * column would destroy the guarantee for every scope that predates this
     * (see {@see \EruoFood\Shared\Infrastructure\Idempotency\EloquentIdempotencyStore}).
     *
     * Two further properties follow, both wanted:
     *
     * - the client's raw key is never persisted, and never appears in an
     *   `IdempotencyConflict` message or the log line carrying it;
     * - the stored value is always 64 characters, so no key can overflow the
     *   column and no two keys are silently truncated onto each other.
     *
     * The digest is taken over the *untruncated* header. A key longer than
     * {@see MAX_IDEMPOTENCY_KEY_LENGTH} is refused rather than cut down: two
     * keys differing only past that point are materially different requests, and
     * collapsing them onto one claim would replay the wrong answer.
     *
     * @throws InvalidArgumentException when the header is present but too long
     */
    protected function principalScopedIdempotencyKey(Request $request, string $principalId): ?string
    {
        $key = $request->header('Idempotency-Key');

        if (! is_string($key)) {
            return null;
        }

        $key = trim($key);

        if ($key === '') {
            return null;
        }

        if (mb_strlen($key) > self::MAX_IDEMPOTENCY_KEY_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'Idempotency-Key must be at most %d characters.',
                self::MAX_IDEMPOTENCY_KEY_LENGTH,
            ));
        }

        // NUL separates the two fields so no principal/key pair can be spelled
        // by a different pair — neither part may contain it.
        return hash('sha256', $principalId."\0".$key);
    }

    /**
     * The same derivation, for an endpoint on which the key is mandatory.
     *
     * Most money-moving endpoints here treat the header as optional: a caller
     * that omits it simply gets the old at-least-once behaviour. That is a
     * reasonable default for an operation whose duplicate is one extra charge
     * the customer can see and dispute.
     *
     * A subscription is not that. It is a standing instruction, so a duplicate
     * is one extra charge *every billing period*, and two identical
     * subscriptions are indistinguishable from a customer who wanted two — no
     * later reconciliation can tell them apart. On this endpoint the guard is
     * therefore not optional, and a caller that omits the header is told so
     * rather than being quietly served the unguarded path.
     *
     * @throws InvalidArgumentException when the header is missing, blank or
     *                                  longer than {@see MAX_IDEMPOTENCY_KEY_LENGTH}
     */
    protected function requirePrincipalScopedIdempotencyKey(Request $request, string $principalId): string
    {
        return $this->principalScopedIdempotencyKey($request, $principalId)
            ?? throw new InvalidArgumentException('An Idempotency-Key header is required for this request.');
    }

    /**
     * A stable fingerprint of what the caller asked for.
     *
     * Keys are sorted so an equivalent request with its fields in a different
     * order still counts as the same request.
     *
     * @param array<string, mixed> $payload
     */
    protected function requestFingerprint(array $payload): string
    {
        ksort($payload);

        return hash('sha256', (string) json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
