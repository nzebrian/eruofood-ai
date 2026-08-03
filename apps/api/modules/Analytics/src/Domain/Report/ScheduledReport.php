<?php

declare(strict_types=1);

namespace EruoFood\Analytics\Domain\Report;

use DateTimeImmutable;
use EruoFood\Analytics\Domain\Enum\ExportFormat;
use EruoFood\Analytics\Domain\Enum\ReportCadence;

/**
 * A recurring report delivered by email on a cadence. Owns its next-run schedule;
 * a worker generates the report for its key/range, exports it in the chosen
 * format and mails it to the recipients, then advances the schedule.
 */
final class ScheduledReport
{
    /**
     * @param list<string> $recipients
     */
    private function __construct(
        private readonly string $id,
        private string $name,
        private readonly string $reportKey,
        private ReportCadence $cadence,
        private ExportFormat $format,
        private array $recipients,
        private bool $active,
        private DateTimeImmutable $nextRunAt,
        private ?DateTimeImmutable $lastRunAt,
        private readonly DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * @param list<string> $recipients
     */
    public static function create(
        string $id,
        string $name,
        string $reportKey,
        ReportCadence $cadence,
        ExportFormat $format,
        array $recipients,
        DateTimeImmutable $now,
    ): self {
        return new self(
            $id,
            $name,
            $reportKey,
            $cadence,
            $format,
            array_values($recipients),
            true,
            $cadence->advance($now),
            null,
            $now,
        );
    }

    /**
     * @param list<string> $recipients
     */
    public static function reconstitute(
        string $id,
        string $name,
        string $reportKey,
        ReportCadence $cadence,
        ExportFormat $format,
        array $recipients,
        bool $active,
        DateTimeImmutable $nextRunAt,
        ?DateTimeImmutable $lastRunAt,
        DateTimeImmutable $createdAt,
    ): self {
        return new self(
            $id,
            $name,
            $reportKey,
            $cadence,
            $format,
            array_values($recipients),
            $active,
            $nextRunAt,
            $lastRunAt,
            $createdAt,
        );
    }

    public function markRun(DateTimeImmutable $at): void
    {
        $this->lastRunAt = $at;
        $this->nextRunAt = $this->cadence->advance($at);
    }

    public function deactivate(): void
    {
        $this->active = false;
    }

    public function isDue(DateTimeImmutable $now): bool
    {
        return $this->active && $this->nextRunAt <= $now;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function reportKey(): string
    {
        return $this->reportKey;
    }

    public function cadence(): ReportCadence
    {
        return $this->cadence;
    }

    public function format(): ExportFormat
    {
        return $this->format;
    }

    /** @return list<string> */
    public function recipients(): array
    {
        return $this->recipients;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function nextRunAt(): DateTimeImmutable
    {
        return $this->nextRunAt;
    }

    public function lastRunAt(): ?DateTimeImmutable
    {
        return $this->lastRunAt;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
