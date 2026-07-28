<?php

declare(strict_types=1);

namespace EruoFood\Analytics\Domain\Enum;

/**
 * A time-bucket granularity for a metric series. Metrics are collected into
 * daily buckets and rolled up to weekly/monthly on read.
 */
enum Granularity: string
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';

    /** Format a date into this granularity's bucket key. */
    public function bucketOf(\DateTimeImmutable $date): string
    {
        return match ($this) {
            self::Day => $date->format('Y-m-d'),
            self::Week => $date->format('o-\WW'),
            self::Month => $date->format('Y-m'),
        };
    }
}
