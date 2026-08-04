<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Application\DTO;

use EruoFood\Shared\Domain\ValueObject\Money;

/** A computed delivery fee plus the zone (if any) it was derived from. */
final readonly class DeliveryQuote
{
    public function __construct(
        public Money $fee,
        public ?string $zoneName,
    ) {
    }
}
