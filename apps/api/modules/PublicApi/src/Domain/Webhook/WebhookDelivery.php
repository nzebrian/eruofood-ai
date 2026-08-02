<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Domain\Webhook;

use DateTimeImmutable;
use EruoFood\PublicApi\Domain\Enum\DeliveryStatus;

/**
 * One attempt-tracked delivery of an event to a webhook. `eventId` is the source
 * domain event's id and is unique per (webhook, event) so a replayed event never
 * creates a duplicate delivery (idempotency). Failed attempts are rescheduled
 * with exponential backoff until the max-attempts ceiling, then marked failed.
 */
final class WebhookDelivery
{
    private function __construct(
        private readonly string $id,
        private readonly string $webhookId,
        private readonly string $eventId,
        private readonly string $eventName,
        private readonly string $payload,
        private DeliveryStatus $status,
        private int $attempts,
        private ?int $lastResponseCode,
        private ?string $lastError,
        private readonly DateTimeImmutable $createdAt,
        private ?DateTimeImmutable $nextAttemptAt,
        private ?DateTimeImmutable $deliveredAt,
    ) {
    }

    public static function queue(
        string $id,
        string $webhookId,
        string $eventId,
        string $eventName,
        string $payload,
        DateTimeImmutable $now,
    ): self {
        return new self($id, $webhookId, $eventId, $eventName, $payload, DeliveryStatus::Pending, 0, null, null, $now, $now, null);
    }

    public static function reconstitute(
        string $id,
        string $webhookId,
        string $eventId,
        string $eventName,
        string $payload,
        DeliveryStatus $status,
        int $attempts,
        ?int $lastResponseCode,
        ?string $lastError,
        DateTimeImmutable $createdAt,
        ?DateTimeImmutable $nextAttemptAt,
        ?DateTimeImmutable $deliveredAt,
    ): self {
        return new self($id, $webhookId, $eventId, $eventName, $payload, $status, $attempts, $lastResponseCode, $lastError, $createdAt, $nextAttemptAt, $deliveredAt);
    }

    /**
     * Record the outcome of an attempt. On success → Delivered; on failure →
     * Retrying (with backoff) until attempts reach $maxAttempts, then Failed.
     */
    public function recordAttempt(bool $success, ?int $code, ?string $error, int $maxAttempts, int $backoffBaseSeconds, DateTimeImmutable $now): void
    {
        $this->attempts++;
        $this->lastResponseCode = $code;
        $this->lastError = $error;

        if ($success) {
            $this->status = DeliveryStatus::Delivered;
            $this->deliveredAt = $now;
            $this->nextAttemptAt = null;

            return;
        }

        if ($this->attempts >= $maxAttempts) {
            $this->status = DeliveryStatus::Failed;
            $this->nextAttemptAt = null;

            return;
        }

        $this->status = DeliveryStatus::Retrying;
        $delay = $backoffBaseSeconds * (2 ** ($this->attempts - 1));
        $this->nextAttemptAt = $now->modify(sprintf('+%d seconds', $delay));
    }

    public function isDue(DateTimeImmutable $now): bool
    {
        return ! $this->status->isTerminal()
            && $this->nextAttemptAt !== null
            && $this->nextAttemptAt <= $now;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function webhookId(): string
    {
        return $this->webhookId;
    }

    public function eventId(): string
    {
        return $this->eventId;
    }

    public function eventName(): string
    {
        return $this->eventName;
    }

    public function payload(): string
    {
        return $this->payload;
    }

    public function status(): DeliveryStatus
    {
        return $this->status;
    }

    public function attempts(): int
    {
        return $this->attempts;
    }

    public function lastResponseCode(): ?int
    {
        return $this->lastResponseCode;
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function nextAttemptAt(): ?DateTimeImmutable
    {
        return $this->nextAttemptAt;
    }

    public function deliveredAt(): ?DateTimeImmutable
    {
        return $this->deliveredAt;
    }
}
