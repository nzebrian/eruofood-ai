<?php

declare(strict_types=1);

namespace EruoFood\Payments\Domain\Webhook;

/**
 * Idempotency store for inbound provider webhooks. A provider may deliver the
 * same event more than once; recording each (provider, event id) and refusing
 * duplicates makes webhook processing exactly-once.
 */
interface WebhookEventRepository
{
    /** True if this provider event has already been recorded. */
    public function seen(string $provider, string $eventId): bool;

    /** Record that a provider event has been processed. */
    public function record(string $provider, string $eventId, string $type): void;
}
