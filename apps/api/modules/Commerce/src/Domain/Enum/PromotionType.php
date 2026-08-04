<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Domain\Enum;

/** How a promotion discounts the products it applies to. */
enum PromotionType: string
{
    case Percentage = 'percentage'; // percent off
    case Fixed = 'fixed';           // fixed minor-units off
}
