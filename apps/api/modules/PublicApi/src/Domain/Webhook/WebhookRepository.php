<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Domain\Webhook;

use DateTimeImmutable;

/** Persistence port for {@see Webhook} endpoints and their {@see WebhookDelivery} log. */
interface WebhookRepository
{
    public function nextIdentity(): string;

    public function nextDeliveryIdentity(): string;

    public function findById(string $id): ?Webhook;

    /**
     * @return list<Webhook>
     */
    public function forApplication(string $applicationId): array;

    /**
     * Every active webhook (across applications) subscribed to an event name —
     * the fan-out target set when an internal event is published.
     *
     * @return list<Webhook>
     */
    public function subscribedTo(string $eventName): array;

    public function save(Webhook $webhook): void;

    // --- deliveries ---

    public function findDelivery(string $id): ?WebhookDelivery;

    /** Whether a delivery already exists for (webhook, event) — idempotency guard. */
    public function deliveryExists(string $webhookId, string $eventId): bool;

    /**
     * @return list<WebhookDelivery>
     */
    public function dueDeliveries(DateTimeImmutable $now, int $limit): array;

    /**
     * @return list<WebhookDelivery>
     */
    public function deliveriesForWebhook(string $webhookId, int $limit): array;

    public function saveDelivery(WebhookDelivery $delivery): void;
}
