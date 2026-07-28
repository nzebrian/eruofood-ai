<?php

declare(strict_types=1);

namespace EruoFood\Support\Domain\Sla;

use DateInterval;
use DateTimeImmutable;
use EruoFood\Support\Domain\Enum\TicketPriority;

/**
 * A service-level agreement for a priority: how long support has to make first
 * response and to resolve. Targets are in minutes (calendar time; business-hour
 * calendars are architecture-ready). One policy per priority is seeded from
 * config, but policies are editable and versionable as their own aggregate.
 */
final class SlaPolicy
{
    private function __construct(
        private readonly string $id,
        private string $name,
        private readonly TicketPriority $priority,
        private int $firstResponseMinutes,
        private int $resolutionMinutes,
    ) {
    }

    public static function define(
        string $id,
        string $name,
        TicketPriority $priority,
        int $firstResponseMinutes,
        int $resolutionMinutes,
    ): self {
        return new self($id, $name, $priority, $firstResponseMinutes, $resolutionMinutes);
    }

    public static function reconstitute(
        string $id,
        string $name,
        TicketPriority $priority,
        int $firstResponseMinutes,
        int $resolutionMinutes,
    ): self {
        return new self($id, $name, $priority, $firstResponseMinutes, $resolutionMinutes);
    }

    public function update(string $name, int $firstResponseMinutes, int $resolutionMinutes): void
    {
        $this->name = $name;
        $this->firstResponseMinutes = $firstResponseMinutes;
        $this->resolutionMinutes = $resolutionMinutes;
    }

    public function firstResponseDueAt(DateTimeImmutable $from): DateTimeImmutable
    {
        return $from->add(new DateInterval('PT'.$this->firstResponseMinutes.'M'));
    }

    public function resolutionDueAt(DateTimeImmutable $from): DateTimeImmutable
    {
        return $from->add(new DateInterval('PT'.$this->resolutionMinutes.'M'));
    }

    public function id(): string
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function priority(): TicketPriority
    {
        return $this->priority;
    }

    public function firstResponseMinutes(): int
    {
        return $this->firstResponseMinutes;
    }

    public function resolutionMinutes(): int
    {
        return $this->resolutionMinutes;
    }
}
