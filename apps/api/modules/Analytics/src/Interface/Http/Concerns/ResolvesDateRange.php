<?php

declare(strict_types=1);

namespace EruoFood\Analytics\Interface\Http\Concerns;

use DateTimeImmutable;
use EruoFood\Analytics\Domain\ValueObject\DateRange;
use Illuminate\Http\Request;

/** Parses a report/dashboard date range from `from`+`to` or `days` query params. */
trait ResolvesDateRange
{
    protected function resolveRange(Request $request, int $defaultDays): DateRange
    {
        if ($request->filled('from') && $request->filled('to')) {
            return new DateRange(
                (new DateTimeImmutable((string) $request->string('from')))->setTime(0, 0, 0),
                (new DateTimeImmutable((string) $request->string('to')))->setTime(23, 59, 59),
            );
        }

        return DateRange::lastDays((int) $request->integer('days', $defaultDays));
    }
}
