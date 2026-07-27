<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Domain\Broadcast;

use DateTimeImmutable;
use EruoFood\Notifications\Domain\Enum\NotificationCategory;

/**
 * An admin broadcast / marketing campaign — a message fanned out to an audience
 * segment over one or more channels, optionally scheduled. Tracks how many
 * recipients it was dispatched to. The Campaign Manager creates and sends these.
 */
final class Broadcast
{
    /**
     * @param list<string> $channels channel values
     */
    private function __construct(
        private readonly string $id,
        private string $title,
        private string $body,
        private readonly NotificationCategory $category,
        private array $channels,
        private string $segment,
        private ?DateTimeImmutable $scheduledFor,
        private bool $sent,
        private int $recipientCount,
        private readonly DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * @param list<string> $channels
     */
    public static function create(
        string $id,
        string $title,
        string $body,
        NotificationCategory $category,
        array $channels,
        string $segment,
        ?DateTimeImmutable $scheduledFor,
        DateTimeImmutable $now,
    ): self {
        return new self($id, $title, $body, $category, array_values($channels), $segment, $scheduledFor, false, 0, $now);
    }

    /**
     * @param list<string> $channels
     */
    public static function reconstitute(
        string $id,
        string $title,
        string $body,
        NotificationCategory $category,
        array $channels,
        string $segment,
        ?DateTimeImmutable $scheduledFor,
        bool $sent,
        int $recipientCount,
        DateTimeImmutable $createdAt,
    ): self {
        return new self($id, $title, $body, $category, array_values($channels), $segment, $scheduledFor, $sent, $recipientCount, $createdAt);
    }

    public function markSent(int $recipientCount): void
    {
        $this->sent = true;
        $this->recipientCount = $recipientCount;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function category(): NotificationCategory
    {
        return $this->category;
    }

    /** @return list<string> */
    public function channels(): array
    {
        return $this->channels;
    }

    public function segment(): string
    {
        return $this->segment;
    }

    public function scheduledFor(): ?DateTimeImmutable
    {
        return $this->scheduledFor;
    }

    public function isSent(): bool
    {
        return $this->sent;
    }

    public function recipientCount(): int
    {
        return $this->recipientCount;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
