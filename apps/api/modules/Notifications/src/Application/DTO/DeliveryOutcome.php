<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Application\DTO;

/**
 * The result of a channel sender attempting to deliver a notification.
 *
 * `providerMessageId` is the handle support needs when somebody says the message
 * never arrived. `retryable` distinguishes a transient failure from a permanent
 * one, so the retry loop does not spend its attempts on an address that will
 * never accept mail.
 */
final readonly class DeliveryOutcome
{
    public function __construct(
        public bool $success,
        public ?string $detail = null,
        public ?string $providerMessageId = null,
        public bool $retryable = true,
    ) {
    }

    public static function ok(?string $detail = null, ?string $providerMessageId = null): self
    {
        return new self(true, $detail, $providerMessageId);
    }

    /** A failure worth trying again — a timeout, a rate limit, an outage. */
    public static function failed(string $detail): self
    {
        return new self(false, $detail, null, retryable: true);
    }

    /**
     * A failure retrying cannot fix — an invalid address, a missing recipient.
     *
     * Kept distinct so the retry loop can stop rather than re-attempting the
     * same impossible send until the cap is reached.
     */
    public static function permanentlyFailed(string $detail): self
    {
        return new self(false, $detail, null, retryable: false);
    }
}
