<?php

declare(strict_types=1);

namespace EruoFood\Payments\Domain\Enum;

/**
 * The kinds of account that hold a wallet. The platform wallet is the escrow /
 * commission sink; customer/vendor/restaurant/driver wallets hold their funds.
 */
enum WalletOwnerType: string
{
    case Customer = 'customer';
    case Restaurant = 'restaurant';
    case Vendor = 'vendor';
    case Driver = 'driver';
    case Platform = 'platform'; // admin / escrow / commission sink
}
