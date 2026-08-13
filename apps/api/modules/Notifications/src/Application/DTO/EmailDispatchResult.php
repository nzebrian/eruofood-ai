<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Application\DTO;

/**
 * What the provider said.
 *
 * Two fields carry real operational weight:
 *
 * `providerMessageId` is the handle support needs when a customer says the mail
 * never arrived — without it, "we sent it" is an unverifiable claim.
 *
 * `retryable` separates a transient failure (timeout, rate limit, 5xx) from a
 * permanent one (invalid address, hard bounce). Retrying a permanent failure
 * burns quota and reputation forever and will never succeed, so the distinction
 * has to come from the adapter that actually saw the response.
 */
final readonly class EmailDispatchResult
{
    private function __construct(
        public bool $success,
        public ?string $providerMessageId = null,
        public ?string $detail = null,
        public bool $retryable = false,
    ) {
    }

    public static function sent(?string $providerMessageId = null, ?string $detail = null): self
    {
        return new self(true, $providerMessageId, $detail);
    }

    /** A transient failure — worth trying again. */
    public static function transientFailure(string $detail): self
    {
        return new self(false, null, $detail, retryable: true);
    }

    /** A permanent failure — retrying cannot help. */
    public static function permanentFailure(string $detail): self
    {
        return new self(false, null, $detail, retryable: false);
    }
}
