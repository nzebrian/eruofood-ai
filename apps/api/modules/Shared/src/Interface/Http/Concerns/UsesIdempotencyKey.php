<?php

declare(strict_types=1);

namespace EruoFood\Shared\Interface\Http\Concerns;

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
        return $key === '' ? null : mb_substr($key, 0, 255);
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
