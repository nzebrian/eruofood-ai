<?php

declare(strict_types=1);

namespace EruoFood\Analytics\Domain\Enum;

/** The lifecycle of a generated report. */
enum ReportStatus: string
{
    case Pending = 'pending';
    case Ready = 'ready';
    case Failed = 'failed';
}
