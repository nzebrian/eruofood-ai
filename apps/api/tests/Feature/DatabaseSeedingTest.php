<?php

declare(strict_types=1);

use EruoFood\Marketplace\Infrastructure\Seeder\MarketplaceSeeder;
use EruoFood\Payments\Domain\Enum\WalletOwnerType;
use EruoFood\Payments\Domain\Wallet\WalletRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|------------------------------------------------------------------------------
| M50-13 regression coverage
|------------------------------------------------------------------------------
| `php artisan db:seed --force` is a step in both Docker certification gates,
| and for two milestones it was masked with `|| true` because it could not
| succeed: Laravel resolves a root `Database\Seeders\DatabaseSeeder` that this
| repository never had. Underneath that, two seeders could not have run even if
| it had — MarketplaceSeeder's `string $currency` was unbindable, and
| PaymentsSeeder wrote the word 'platform' into a `uuid` column, which SQLite
| tolerated and PostgreSQL rejected with SQLSTATE[22P02].
|
| The masks are gone. These tests are what stops them coming back: if the seed
| breaks again, this fails here rather than being absorbed by a `|| true` in a
| workflow nobody reads.
|
| Migrating a clean database is deliberately NOT re-tested here — `ci-api.yml`
| already proves it against real PostgreSQL with
| `migrate:fresh` → `migrate:rollback` → `migrate`.
*/

uses(RefreshDatabase::class);

/** @return array<string, int> */
function seededRowCounts(): array
{
    $counts = [];
    foreach ([
        'admin_settings', 'catalog_categories', 'catalog_foods', 'catalog_recipes',
        'commerce_stores', 'commerce_products', 'marketplace_vendors',
        'payments_wallets', 'loyalty_rewards', 'reviews',
    ] as $table) {
        $counts[$table] = DB::table($table)->count();
    }

    return $counts;
}

it('seeds a freshly migrated database with no masking', function (): void {
    $this->artisan('db:seed', ['--force' => true])->assertExitCode(0);

    // Not merely "it exited 0" — an empty seed would do that too.
    expect(seededRowCounts())->each->toBeGreaterThan(0);
});

it('can be seeded twice without failing or duplicating', function (): void {
    $this->artisan('db:seed', ['--force' => true])->assertExitCode(0);
    $afterFirst = seededRowCounts();

    // The unique slugs on catalog_categories and commerce_stores used to abort
    // the second run outright; a seeder that de-duplicates its slug instead
    // would pass an exit-code check while quietly growing a second copy. Both
    // are covered by comparing the counts rather than just the exit code.
    $this->artisan('db:seed', ['--force' => true])->assertExitCode(0);

    expect(seededRowCounts())->toBe($afterFirst);
});

it('resolves MarketplaceSeeder with its currency dependency bound', function (): void {
    // MarketplaceServiceProvider binds `$currency` contextually. Without the
    // seeder in that list this throws BindingResolutionException: "Unresolvable
    // dependency resolving [Parameter #3 [ <required> string $currency ]]".
    expect(app(MarketplaceSeeder::class))->toBeInstanceOf(MarketplaceSeeder::class);
});

it('identifies the platform wallet by a UUID, not a word', function (): void {
    // `payments_wallets.owner_id` is a uuid column. This is the guard against
    // anyone putting a bare label back in.
    expect(Str::isUuid(WalletOwnerType::PLATFORM_OWNER_ID))->toBeTrue();

    $this->artisan('db:seed', ['--force' => true])->assertExitCode(0);

    $platform = DB::table('payments_wallets')->where('owner_type', WalletOwnerType::Platform->value)->first();

    expect($platform)->not->toBeNull()
        ->and($platform->owner_id)->toBe(WalletOwnerType::PLATFORM_OWNER_ID);
});

it('looks the platform wallet up without a PostgreSQL uuid error', function (): void {
    // The exact call WalletService::payFromWallet() makes to reach the escrow
    // wallet. With the old 'platform' literal this raised SQLSTATE[22P02] on
    // PostgreSQL before any wallet could be found or opened.
    $wallets = app(WalletRepository::class);

    expect($wallets->findForOwner(WalletOwnerType::Platform, WalletOwnerType::PLATFORM_OWNER_ID))->toBeNull();

    $this->artisan('db:seed', ['--force' => true])->assertExitCode(0);

    $platform = $wallets->findForOwner(WalletOwnerType::Platform, WalletOwnerType::PLATFORM_OWNER_ID);

    expect($platform)->not->toBeNull()
        ->and($platform->ownerId())->toBe(WalletOwnerType::PLATFORM_OWNER_ID);
});
