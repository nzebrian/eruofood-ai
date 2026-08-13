<?php

declare(strict_types=1);

namespace EruoFood\Shared\Domain\Exception;

/**
 * An idempotency key was presented in a way that cannot be honoured safely.
 *
 * Two distinct situations, deliberately separated because the client's next move
 * differs:
 *
 * - {@see inFlight()} — the first request carrying this key is still running.
 *   Retrying later is correct; running now would duplicate the effect.
 * - {@see reused()} — the key was already used for a *different* payload.
 *   Replaying the stored response would answer a question the client did not
 *   ask, so the request is refused outright.
 */
final class IdempotencyConflict extends DomainException
{
    /** Named `$errorCode`, not `$code` — the latter is already Exception's own. */
    private function __construct(string $message, private readonly string $errorCode)
    {
        parent::__construct($message);
    }

    public static function inFlight(string $key): self
    {
        return new self(
            sprintf('A request with idempotency key "%s" is still being processed. Retry in a moment.', $key),
            'IDEMPOTENCY_IN_FLIGHT',
        );
    }

    public static function reused(string $key): self
    {
        return new self(
            sprintf('Idempotency key "%s" was already used for a different request.', $key),
            'IDEMPOTENCY_KEY_REUSED',
        );
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
