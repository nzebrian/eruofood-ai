<?php

declare(strict_types=1);

namespace EruoFood\Analytics\Domain\Report;

use DateTimeImmutable;
use EruoFood\Analytics\Domain\Enum\ReportStatus;
use EruoFood\Analytics\Domain\ValueObject\DateRange;

/**
 * A generated tabular report — its definition (key + range), the produced
 * columns and rows, and a status. Reports are the exportable, point-in-time
 * output of the report generator (financial, sales, AI-cost, etc.).
 *
 * @phpstan-type Row list<string|int|float>
 */
final class Report
{
    /**
     * @param list<string> $columns
     * @param list<list<string|int|float>> $rows
     */
    private function __construct(
        private readonly string $id,
        private readonly string $key,
        private readonly string $title,
        private readonly DateRange $range,
        private array $columns,
        private array $rows,
        private ReportStatus $status,
        private readonly DateTimeImmutable $generatedAt,
    ) {
    }

    public static function pending(string $id, string $key, string $title, DateRange $range, DateTimeImmutable $now): self
    {
        return new self($id, $key, $title, $range, [], [], ReportStatus::Pending, $now);
    }

    /**
     * @param list<string> $columns
     * @param list<list<string|int|float>> $rows
     */
    public static function ready(
        string $id,
        string $key,
        string $title,
        DateRange $range,
        array $columns,
        array $rows,
        DateTimeImmutable $now,
    ): self {
        return new self($id, $key, $title, $range, array_values($columns), array_values($rows), ReportStatus::Ready, $now);
    }

    /**
     * @param list<string> $columns
     * @param list<list<string|int|float>> $rows
     */
    public static function reconstitute(
        string $id,
        string $key,
        string $title,
        DateRange $range,
        array $columns,
        array $rows,
        ReportStatus $status,
        DateTimeImmutable $generatedAt,
    ): self {
        return new self($id, $key, $title, $range, array_values($columns), array_values($rows), $status, $generatedAt);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function range(): DateRange
    {
        return $this->range;
    }

    /** @return list<string> */
    public function columns(): array
    {
        return $this->columns;
    }

    /** @return list<list<string|int|float>> */
    public function rows(): array
    {
        return $this->rows;
    }

    public function status(): ReportStatus
    {
        return $this->status;
    }

    public function generatedAt(): DateTimeImmutable
    {
        return $this->generatedAt;
    }
}
