<?php

declare(strict_types=1);

namespace EruoFood\Payments\Application\DTO;

/**
 * A normalised inbound webhook event, parsed from a provider's raw payload.
 * `eventId` drives idempotency; `type` is normalised to
 * payment.succeeded|payment.failed|refund.completed|transfer.completed.
 */
final readonly class WebhookPayload
{
    public function __construct(
        public string $eventId,
        public string $type,
        public string $providerReference,
        public string $status,
        public int $amountMinor,
    ) {
    }
}
