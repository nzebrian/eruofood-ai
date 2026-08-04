<?php

declare(strict_types=1);

namespace EruoFood\Analytics\Domain\Enum;

/** How an event contributes to a metric: a count (+1) or a sum (+value). */
enum MetricOp: string
{
    case Count = 'count';
    case Sum = 'sum';
}
