<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Application\Service;

use DateTimeImmutable;
use EruoFood\PublicApi\Application\Port\WebhookDispatcher;
use EruoFood\PublicApi\Application\Port\WebhookUrlGuard;
use EruoFood\PublicApi\Domain\Event\WebhookDelivered;
use EruoFood\PublicApi\Domain\Event\WebhookFailed;
use EruoFood\PublicApi\Domain\Exception\PublicApiNotFound;
use EruoFood\PublicApi\Domain\Webhook\Webhook;
use EruoFood\PublicApi\Domain\Webhook\WebhookDelivery;
use EruoFood\PublicApi\Domain\Webhook\WebhookRepository;
use EruoFood\Shared\Domain\EventBus;

/**
 * The webhook system: endpoint management plus signed, retried, idempotent
 * delivery. When an internal event is published, {@see dispatchEvent()} fans it
 * out to every active subscriber exactly once per (webhook, event) — replayed
 * events are ignored. Each attempt is HMAC-signed and logged; failures are
 * rescheduled with exponential backoff until the attempt ceiling.
 */
final readonly class WebhookService
{
    /**
     * @param array{signature_header: string, timestamp_header: string, id_header: string, max_attempts: int, backoff_base_seconds: int, timeout_seconds: int} $config
     */
    public function __construct(
        private WebhookRepository $webhooks,
        private WebhookDispatcher $dispatcher,
        private WebhookSigner $signer,
        private EventBus $events,
        private WebhookUrlGuard $urlGuard,
        private array $config,
    ) {
    }

    /**
     * @param list<string> $events
     */
    public function subscribe(string $applicationId, string $url, array $events): Webhook
    {
        // Refuse SSRF-unsafe destinations before the endpoint is ever stored.
        $this->urlGuard->assertAllowed($url);

        $webhook = Webhook::create(
            $this->webhooks->nextIdentity(),
            $applicationId,
            $url,
            $events,
            $this->generateSecret(),
            new DateTimeImmutable(),
        );
        $this->webhooks->save($webhook);

        return $webhook;
    }

    /**
     * @param list<string> $events
     */
    public function update(string $id, string $applicationId, string $url, array $events): Webhook
    {
        $this->urlGuard->assertAllowed($url);
        $webhook = $this->owned($id, $applicationId);
        $webhook->update($url, $events, new DateTimeImmutable());
        $this->webhooks->save($webhook);

        return $webhook;
    }

    public function rotateSecret(string $id, string $applicationId): Webhook
    {
        $webhook = $this->owned($id, $applicationId);
        $webhook->rotateSecret($this->generateSecret(), new DateTimeImmutable());
        $this->webhooks->save($webhook);

        return $webhook;
    }

    public function disable(string $id, string $applicationId): Webhook
    {
        $webhook = $this->owned($id, $applicationId);
        $webhook->disable(new DateTimeImmutable());
        $this->webhooks->save($webhook);

        return $webhook;
    }

    /**
     * @return list<Webhook>
     */
    public function forApplication(string $applicationId): array
    {
        return $this->webhooks->forApplication($applicationId);
    }

    /**
     * @return list<WebhookDelivery>
     */
    public function deliveries(string $webhookId, string $applicationId, int $limit): array
    {
        $this->owned($webhookId, $applicationId);

        return $this->webhooks->deliveriesForWebhook($webhookId, $limit);
    }

    /**
     * Fan an event out to all active subscribers, once per (webhook, event).
     *
     * @param array<string, mixed> $data
     */
    public function dispatchEvent(string $publicEventName, string $eventId, array $data): void
    {
        foreach ($this->webhooks->subscribedTo($publicEventName) as $webhook) {
            if ($this->webhooks->deliveryExists($webhook->id(), $eventId)) {
                continue; // idempotent — already queued/delivered for this event
            }
            $payload = $this->buildPayload($eventId, $publicEventName, $data);
            $delivery = WebhookDelivery::queue(
                $this->webhooks->nextDeliveryIdentity(),
                $webhook->id(),
                $eventId,
                $publicEventName,
                $payload,
                new DateTimeImmutable(),
            );
            $this->webhooks->saveDelivery($delivery);
            $this->attempt($delivery, $webhook);
        }
    }

    /** Retry deliveries whose backoff has elapsed. Returns the count attempted. */
    public function retryDue(int $limit): int
    {
        $count = 0;
        foreach ($this->webhooks->dueDeliveries(new DateTimeImmutable(), $limit) as $delivery) {
            $webhook = $this->webhooks->findById($delivery->webhookId());
            if ($webhook === null) {
                continue;
            }
            $this->attempt($delivery, $webhook);
            $count++;
        }

        return $count;
    }

    private function attempt(WebhookDelivery $delivery, Webhook $webhook): void
    {
        $now = new DateTimeImmutable();
        $timestamp = $now->getTimestamp();
        $signature = $this->signer->sign($delivery->payload(), $webhook->secret(), $timestamp);

        $result = $this->dispatcher->post(
            $webhook->url(),
            $delivery->payload(),
            [
                'Content-Type' => 'application/json',
                $this->config['signature_header'] => $signature,
                $this->config['timestamp_header'] => (string) $timestamp,
                $this->config['id_header'] => $delivery->eventId(),
            ],
            $this->config['timeout_seconds'],
        );

        $delivery->recordAttempt(
            $result->success,
            $result->statusCode,
            $result->error,
            $this->config['max_attempts'],
            $this->config['backoff_base_seconds'],
            $now,
        );
        $this->webhooks->saveDelivery($delivery);

        if ($result->success) {
            $this->events->publish(new WebhookDelivered($webhook->id(), $delivery->eventName(), $delivery->attempts()));
        } elseif ($delivery->status()->isTerminal()) {
            $this->events->publish(new WebhookFailed($webhook->id(), $delivery->eventName(), $delivery->attempts()));
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function buildPayload(string $eventId, string $eventName, array $data): string
    {
        return json_encode([
            'id' => $eventId,
            'type' => $eventName,
            'created_at' => (new DateTimeImmutable())->format(DATE_ATOM),
            'data' => $data,
        ], JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    private function owned(string $id, string $applicationId): Webhook
    {
        $webhook = $this->webhooks->findById($id) ?? throw PublicApiNotFound::of('webhook', $id);
        $webhook->isOwnedBy($applicationId);

        return $webhook;
    }

    private function generateSecret(): string
    {
        return 'whsec_'.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
