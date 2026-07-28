<?php

declare(strict_types=1);

namespace EruoFood\Analytics\Domain\ValueObject;

use DateTimeImmutable;
use EruoFood\Analytics\Domain\Exception\AnalyticsInvalidState;

/** An inclusive [from, to] date range for a query or report. */
final readonly class DateRange
{
    public function __construct(
        public DateTimeImmutable $from,
        public DateTimeImmutable $to,
    ) {
        if ($to < $from) {
            throw new AnalyticsInvalidState('Range end must not be before its start.');
        }
    }

    public static function lastDays(int $days, ?DateTimeImmutable $now = null): self
    {
        $now ??= new DateTimeImmutable();
        $to = $now->setTime(23, 59, 59);
        $from = $now->modify(sprintf('-%d days', max(0, $days - 1)))->setTime(0, 0, 0);

        return new self($from, $to);
    }

    public function days(): int
    {
        return (int) $this->from->diff($this->to)->days + 1;
    }

    public function fromDate(): string
    {
        return $this->from->format('Y-m-d');
    }

    public function toDate(): string
    {
        return $this->to->format('Y-m-d');
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return ['from' => $this->fromDate(), 'to' => $this->toDate()];
    }
}
