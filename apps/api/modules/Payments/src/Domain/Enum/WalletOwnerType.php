<?php

declare(strict_types=1);

namespace EruoFood\Payments\Domain\Enum;

/**
 * The kinds of account that hold a wallet. The platform wallet is the escrow /
 * commission sink; customer/vendor/restaurant/driver wallets hold their funds.
 */
enum WalletOwnerType: string
{
    /**
     * Owner id of the one platform wallet.
     *
     * Every other wallet owner is a real account and brings its own id. The
     * platform wallet is a singleton with nobody behind it, so its id has to be
     * written down somewhere — and `payments_wallets.owner_id` is a `uuid`
     * column, so it has to be a UUID. It was the string 'platform', which
     * SQLite accepted and PostgreSQL rejected with SQLSTATE[22P02] on every
     * lookup, breaking the escrow leg of `WalletService::payFromWallet()` and
     * `PaymentsSeeder` alike (M50-13).
     *
     * This is the single place that value is defined. Both callers read it from
     * here rather than repeating a literal, because two copies of a sentinel is
     * how one of them quietly becomes wrong.
     */
    public const PLATFORM_OWNER_ID = '00000000-0000-4000-8000-000000000000';

    case Customer = 'customer';
    case Restaurant = 'restaurant';
    case Vendor = 'vendor';
    case Driver = 'driver';
    case Platform = 'platform'; // admin / escrow / commission sink
}
