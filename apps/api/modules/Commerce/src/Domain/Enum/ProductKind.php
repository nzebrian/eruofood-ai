<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Domain\Enum;

/** Whether a product is a grocery line (has a department) or a general good. */
enum ProductKind: string
{
    case Grocery = 'grocery';
    case General = 'general';
}
