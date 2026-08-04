<?php

declare(strict_types=1);

namespace EruoFood\Support\Domain\Crm;

use DateTimeImmutable;

/**
 * The CRM view of a customer — a projection Support maintains from domain events
 * (registrations, orders, payments) and its own ticket activity. Keyed by the
 * Identity user id (soft reference); Support never owns the customer, only this
 * support/CRM projection of them. Holds the aggregates the agent needs at a
 * glance: value segment, order & spend totals, ticket count, tags, agent notes,
 * and the latest AI-generated insight.
 */
final class CustomerProfile
{
    /**
     * @param list<string> $tags
     */
    private function __construct(
        private readonly string $userId,
        private ?string $displayName,
        private ?string $email,
        private CustomerSegment $segment,
        private int $orderCount,
        private int $totalSpentMinor,
        private int $ticketCount,
        private array $tags,
        private ?string $notes,
        private ?string $insight,
        private ?DateTimeImmutable $insightGeneratedAt,
        private ?DateTimeImmutable $lastInteractionAt,
        private readonly DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt,
    ) {
    }

    public static function start(string $userId, ?string $displayName, ?string $email, DateTimeImmutable $now): self
    {
        return new self($userId, $displayName, $email, CustomerSegment::New, 0, 0, 0, [], null, null, null, null, $now, $now);
    }

    /**
     * @param list<string> $tags
     */
    public static function reconstitute(
        string $userId,
        ?string $displayName,
        ?string $email,
        CustomerSegment $segment,
        int $orderCount,
        int $totalSpentMinor,
        int $ticketCount,
        array $tags,
        ?string $notes,
        ?string $insight,
        ?DateTimeImmutable $insightGeneratedAt,
        ?DateTimeImmutable $lastInteractionAt,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
    ): self {
        return new self($userId, $displayName, $email, $segment, $orderCount, $totalSpentMinor, $ticketCount, $tags, $notes, $insight, $insightGeneratedAt, $lastInteractionAt, $createdAt, $updatedAt);
    }

    public function identify(?string $displayName, ?string $email): void
    {
        $this->displayName ??= $displayName;
        $this->email ??= $email;
    }

    /**
     * @param array<string, int> $thresholds
     */
    public function recordOrder(int $amountMinor, array $thresholds, DateTimeImmutable $at): void
    {
        $this->orderCount++;
        $this->totalSpentMinor += max(0, $amountMinor);
        $this->segment = CustomerSegment::fromOrderCount($this->orderCount, $thresholds);
        $this->touch($at);
    }

    public function recordTicket(DateTimeImmutable $at): void
    {
        $this->ticketCount++;
        $this->touch($at);
    }

    public function touch(DateTimeImmutable $at): void
    {
        $this->lastInteractionAt = $at;
        $this->updatedAt = $at;
    }

    public function addTag(string $tag): void
    {
        if (! in_array($tag, $this->tags, true)) {
            $this->tags[] = $tag;
        }
    }

    public function setNotes(string $notes): void
    {
        $this->notes = $notes;
    }

    public function setInsight(string $insight, DateTimeImmutable $at): void
    {
        $this->insight = $insight;
        $this->insightGeneratedAt = $at;
        $this->updatedAt = $at;
    }

    public function userId(): string
    {
        return $this->userId;
    }

    public function displayName(): ?string
    {
        return $this->displayName;
    }

    public function email(): ?string
    {
        return $this->email;
    }

    public function segment(): CustomerSegment
    {
        return $this->segment;
    }

    public function orderCount(): int
    {
        return $this->orderCount;
    }

    public function totalSpentMinor(): int
    {
        return $this->totalSpentMinor;
    }

    public function ticketCount(): int
    {
        return $this->ticketCount;
    }

    /** @return list<string> */
    public function tags(): array
    {
        return $this->tags;
    }

    public function notes(): ?string
    {
        return $this->notes;
    }

    public function insight(): ?string
    {
        return $this->insight;
    }

    public function insightGeneratedAt(): ?DateTimeImmutable
    {
        return $this->insightGeneratedAt;
    }

    public function lastInteractionAt(): ?DateTimeImmutable
    {
        return $this->lastInteractionAt;
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
