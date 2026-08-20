<?php

declare(strict_types=1);

namespace EruoFood\Payments\Infrastructure\Directory;

use EruoFood\Payments\Application\Port\MerchantDirectory;
use Illuminate\Support\Facades\DB;

/**
 * Reads merchant ownership straight from Marketplace's tables.
 *
 * A soft reference — table and column names, no class import, no foreign key —
 * the boundary convention this codebase already uses for cross-context reads.
 *
 * ## Only vendors and riders, and nothing invented for the rest
 *
 * `vendor` and `restaurant` are both Marketplace vendors; `driver` is a
 * Marketplace rider. Anything else returns null, which callers treat as "nobody
 * to notify and nobody authorised". A directory that guessed at an owner for an
 * unknown payee type would be handing somebody else's settlement to whoever the
 * guess landed on.
 */
final class TableMerchantDirectory implements MerchantDirectory
{
    public function ownerOf(string $merchantType, string $merchantId): ?string
    {
        $value = match ($merchantType) {
            'vendor', 'restaurant' => DB::table('marketplace_vendors')->where('id', $merchantId)->value('owner_user_id'),
            'driver' => DB::table('marketplace_riders')->where('id', $merchantId)->value('user_id'),
            default => null,
        };

        return $value === null ? null : (string) $value;
    }

    public function merchantsFor(string $userId): array
    {
        $vendors = DB::table('marketplace_vendors')->where('owner_user_id', $userId)->pluck('id')->all();
        $riders = DB::table('marketplace_riders')->where('user_id', $userId)->pluck('id')->all();

        return array_values(array_map(
            static fn (mixed $id): string => (string) $id,
            [...$vendors, ...$riders],
        ));
    }

    public function nameOf(string $merchantType, string $merchantId): ?string
    {
        $value = match ($merchantType) {
            'vendor', 'restaurant' => DB::table('marketplace_vendors')->where('id', $merchantId)->value('name'),
            'driver' => DB::table('marketplace_riders')->where('id', $merchantId)->value('name'),
            default => null,
        };

        return $value === null ? null : (string) $value;
    }
}
