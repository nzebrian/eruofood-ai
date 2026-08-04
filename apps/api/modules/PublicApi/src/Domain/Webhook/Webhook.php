<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Domain\Webhook;

use DateTimeImmutable;
use EruoFood\PublicApi\Domain\Enum\WebhookStatus;
use EruoFood\PublicApi\Domain\Exception\PublicApiForbidden;

/**
 * A webhook endpoint registered by an application: a target URL, the set of
 * public event names it subscribes to, and a signing secret used to HMAC every
 * payload. The secret is retained (encrypted at rest by the persistence layer)
 * because it must be available to sign each delivery; it can be rotated.
 */
final class Webhook
{
    /**
     * @param list<string> $events
     */
    private function __construct(
        private readonly string $id,
        private readonly string $applicationId,
        private string $url,
        private array $events,
        private string $secret,
        private WebhookStatus $status,
        private readonly DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt,
    ) {
    }

    /**
     * @param list<string> $events
     */
    public static function create(
        string $id,
        string $applicationId,
        string $url,
        array $events,
        string $secret,
        DateTimeImmutable $now,
    ): self {
        return new self($id, $applicationId, $url, $events, $secret, WebhookStatus::Active, $now, $now);
    }

    /**
     * @param list<string> $events
     */
    public static function reconstitute(
        string $id,
        string $applicationId,
        string $url,
        array $events,
        string $secret,
        WebhookStatus $status,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
    ): self {
        return new self($id, $applicationId, $url, $events, $secret, $status, $createdAt, $updatedAt);
    }

    /**
     * @param list<string> $events
     */
    public function update(string $url, array $events, DateTimeImmutable $now): void
    {
        $this->url = $url;
        $this->events = $events;
        $this->updatedAt = $now;
    }

    public function rotateSecret(string $secret, DateTimeImmutable $now): void
    {
        $this->secret = $secret;
        $this->updatedAt = $now;
    }

    public function disable(DateTimeImmutable $now): void
    {
        $this->status = WebhookStatus::Disabled;
        $this->updatedAt = $now;
    }

    public function subscribesTo(string $eventName): bool
    {
        return $this->status->isActive() && in_array($eventName, $this->events, true);
    }

    public function isOwnedBy(string $applicationId): void
    {
        if ($this->applicationId !== $applicationId) {
            throw new PublicApiForbidden('This webhook belongs to another application.');
        }
    }

    public function id(): string
    {
        return $this->id;
    }

    public function applicationId(): string
    {
        return $this->applicationId;
    }

    public function url(): string
    {
        return $this->url;
    }

    /** @return list<string> */
    public function events(): array
    {
        return $this->events;
    }

    public function secret(): string
    {
        return $this->secret;
    }

    public function status(): WebhookStatus
    {
        return $this->status;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
