<?php

declare(strict_types=1);

namespace EruoFood\Analytics\Domain\Metric;

use DateTimeImmutable;
use EruoFood\Analytics\Domain\Enum\AnalyticsCategory;

/**
 * A raw analytics fact collected from a published domain event. It is an
 * append-only record of "something happened" — the source of truth the
 * projection pipeline rolls up into {@see MetricSnapshot}s. No business module
 * writes this; the event subscriber creates it from domain events.
 */
final class AnalyticsEvent
{
    /**
     * @param array<string, string> $dimensions
     */
    private function __construct(
        private readonly string $id,
        private readonly string $name,
        private readonly AnalyticsCategory $category,
        private readonly ?string $actorId,
        private readonly int $value,
        private readonly array $dimensions,
        private readonly DateTimeImmutable $occurredAt,
    ) {
    }

    /**
     * @param array<string, string> $dimensions
     */
    public static function record(
        string $id,
        string $name,
        AnalyticsCategory $category,
        ?string $actorId,
        int $value,
        array $dimensions,
        DateTimeImmutable $occurredAt,
    ): self {
        return new self($id, $name, $category, $actorId, $value, $dimensions, $occurredAt);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function category(): AnalyticsCategory
    {
        return $this->category;
    }

    public function actorId(): ?string
    {
        return $this->actorId;
    }

    public function value(): int
    {
        return $this->value;
    }

    /** @return array<string, string> */
    public function dimensions(): array
    {
        return $this->dimensions;
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
