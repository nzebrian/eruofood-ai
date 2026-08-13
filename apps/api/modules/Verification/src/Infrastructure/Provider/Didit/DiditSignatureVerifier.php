<?php

declare(strict_types=1);

namespace EruoFood\Verification\Infrastructure\Provider\Didit;

use EruoFood\Verification\Domain\Exception\WebhookRejected;

/**
 * Authenticates a Didit webhook before anything else happens to it.
 *
 * Didit signs each delivery three different ways and sends whichever headers
 * apply. All three are HMAC-SHA256 with the destination's shared secret; they
 * differ only in *what* is signed:
 *
 * - `X-Signature-V2`     — the parsed JSON re-encoded with sorted keys.
 * - `X-Signature-Simple` — a canonical string of four fields, so it survives any
 *                          middleware that re-encodes the body.
 * - `X-Signature`        — the raw body exactly as received.
 *
 * We accept any of them. Insisting on one would be brittle: the V2 scheme
 * depends on reproducing another language's JSON encoder byte-for-byte, and the
 * raw-body scheme breaks if anything upstream normalises the payload. Trying
 * each and requiring one to pass is strictly safer than depending on a single
 * fragile reproduction — a forged payload satisfies none of them.
 *
 * Contract source: Didit's own reference implementation
 * (github.com/didit-protocol/didit-full-demo, `src/app/api/verification/webhook/route.ts`).
 * Its published docs site is unreachable from this build environment, so the
 * runnable reference was used instead; §"Known ambiguity" below records the one
 * detail the reference leaves open.
 */
final readonly class DiditSignatureVerifier
{
    public function __construct(
        private string $secret,
        private int $replayToleranceSeconds,
    ) {
    }

    /**
     * Verify the payload and return which scheme proved it.
     *
     * @param array<string, mixed> $decoded the parsed body, used by the V2 and
     *                                      Simple schemes
     *
     * @throws WebhookRejected when no scheme verifies, or the timestamp is stale
     */
    public function verify(string $rawBody, array $decoded, ?string $v2, ?string $simple, ?string $original, int $now): string
    {
        if ($this->secret === '') {
            // A blank secret must never mean "skip the check".
            throw WebhookRejected::badSignature();
        }

        $timestamp = $this->timestampFrom($decoded);
        if ($timestamp === null || abs($now - $timestamp) > $this->replayToleranceSeconds) {
            throw WebhookRejected::replayed();
        }

        if ($v2 !== null && $this->matchesAny($this->v2Candidates($decoded), $v2)) {
            return 'v2';
        }

        if ($simple !== null && $this->matches($this->simplePayload($decoded), $simple)) {
            return 'simple';
        }

        if ($original !== null && $this->matches($rawBody, $original)) {
            return 'original';
        }

        throw WebhookRejected::badSignature();
    }

    /**
     * The V2 scheme signs the JSON re-encoded with sorted keys.
     *
     * Known ambiguity: Didit's backend is Python, whose `json.dumps` defaults to
     * `", "` / `": "` separators, while their JavaScript reference implementation
     * emits compact `,` / `:`. The reference is the runnable artefact so compact
     * is almost certainly right, but the cost of being wrong is rejecting every
     * genuine webhook — so we compute both and accept either. Two HMACs is a
     * negligible price for removing a guess from the authentication path.
     *
     * @param array<string, mixed> $decoded
     * @return list<string>
     */
    private function v2Candidates(array $decoded): array
    {
        $normalised = $this->shortenFloats($decoded);

        return [
            $this->stableJson($normalised, compact: true),
            $this->stableJson($normalised, compact: false),
        ];
    }

    /**
     * The Simple scheme signs "{timestamp}:{session_id}:{status}:{webhook_type}".
     *
     * @param array<string, mixed> $decoded
     */
    private function simplePayload(array $decoded): string
    {
        return implode(':', [
            $this->scalar($decoded, 'timestamp'),
            $this->scalar($decoded, 'session_id'),
            $this->scalar($decoded, 'status'),
            $this->scalar($decoded, 'webhook_type'),
        ]);
    }

    /** @param list<string> $candidates */
    private function matchesAny(array $candidates, string $signature): bool
    {
        foreach ($candidates as $candidate) {
            if ($this->matches($candidate, $signature)) {
                return true;
            }
        }

        return false;
    }

    private function matches(string $payload, string $signature): bool
    {
        return hash_equals(hash_hmac('sha256', $payload, $this->secret), strtolower(trim($signature)));
    }

    /**
     * Didit's timestamp lives in `created_at` on the webhook body; their Simple
     * scheme also carries a `timestamp` field. Prefer the explicit one, fall
     * back to `created_at`, matching the reference implementation.
     *
     * @param array<string, mixed> $decoded
     */
    private function timestampFrom(array $decoded): ?int
    {
        foreach (['timestamp', 'created_at'] as $key) {
            $value = $decoded[$key] ?? null;
            if (is_int($value)) {
                return $value;
            }
            if (is_string($value) && ctype_digit($value)) {
                return (int) $value;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $decoded */
    private function scalar(array $decoded, string $key): string
    {
        $value = $decoded[$key] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Match Python's float handling: a float with no fractional part is written
     * as an integer. PHP's `json_encode` already does this by default, but the
     * value is normalised explicitly so the behaviour does not depend on an
     * encoder flag being left alone.
     */
    private function shortenFloats(mixed $data): mixed
    {
        if (is_array($data)) {
            return array_map(fn (mixed $v): mixed => $this->shortenFloats($v), $data);
        }

        if (is_float($data) && floor($data) === $data && is_finite($data)) {
            return (int) $data;
        }

        return $data;
    }

    /** Deterministic JSON with keys sorted at every level. */
    private function stableJson(mixed $data, bool $compact): string
    {
        if (is_array($data)) {
            $isList = array_is_list($data);

            if (! $isList) {
                ksort($data);
            }

            $parts = [];
            foreach ($data as $key => $value) {
                $encoded = $this->stableJson($value, $compact);
                $parts[] = $isList
                    ? $encoded
                    : $this->encodeScalar((string) $key).($compact ? ':' : ': ').$encoded;
            }

            $separator = $compact ? ',' : ', ';
            $body = implode($separator, $parts);

            return $isList ? '['.$body.']' : '{'.$body.'}';
        }

        return $this->encodeScalar($data);
    }

    private function encodeScalar(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
