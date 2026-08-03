<?php

declare(strict_types=1);

namespace EruoFood\Analytics\Domain\Enum;

use DateTimeImmutable;

/** How often a scheduled report runs. */
enum ReportCadence: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';

    public function advance(DateTimeImmutable $from): DateTimeImmutable
    {
        return match ($this) {
            self::Daily => $from->modify('+1 day'),
            self::Weekly => $from->modify('+1 week'),
            self::Monthly => $from->modify('+1 month'),
        };
    }
}
