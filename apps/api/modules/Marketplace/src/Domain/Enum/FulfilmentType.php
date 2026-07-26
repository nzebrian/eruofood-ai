<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Domain\Enum;

/** How an order is fulfilled. */
enum FulfilmentType: string
{
    case Delivery = 'delivery';
    case Pickup = 'pickup';
}
