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

    /**
     * Take exclusive ownership of a provider event, returning false if another
     * delivery already owns it.
     *
     * The claim is an insert against the unique `(provider, event_id)` index, so
     * the database arbitrates simultaneous deliveries. Checking {@see seen()}
     * and then recording afterwards cannot do that: both deliveries pass the
     * check before either records, and the payment is captured twice.
     *
     * Call inside the same transaction as the work the event triggers, so a
     * failure rolls the claim back and the provider's retry is honoured.
     */
    public function claim(string $provider, string $eventId, string $type): bool;

    /** Record that a provider event has been processed. */
    public function record(string $provider, string $eventId, string $type): void;
}
