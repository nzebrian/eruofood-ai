<?php

declare(strict_types=1);

namespace Database\Seeders;

use EruoFood\Admin\Infrastructure\Seeder\AdminSeeder;
use EruoFood\Ai\Infrastructure\Seeder\DefaultPromptSeeder;
use EruoFood\Catalog\Infrastructure\Seeder\NigerianFoodSeeder;
use EruoFood\Commerce\Infrastructure\Seeder\CommerceSeeder;
use EruoFood\Loyalty\Infrastructure\Seeder\LoyaltySeeder;
use EruoFood\Marketplace\Infrastructure\Seeder\MarketplaceSeeder;
use EruoFood\Notifications\Infrastructure\Seeder\NotificationsSeeder;
use EruoFood\Nutrition\Infrastructure\Seeder\NutritionItemSeeder;
use EruoFood\Payments\Infrastructure\Seeder\PaymentsSeeder;
use EruoFood\Reviews\Infrastructure\Seeder\ReviewsSeeder;
use EruoFood\Support\Infrastructure\Seeder\SupportSeeder;
use Illuminate\Database\Seeder;

/**
 * The root seeder — the entry point `php artisan db:seed` resolves.
 *
 * Seeding in this repository is module-scoped by design, exactly as migrations
 * are: each bounded context owns its own seeder under
 * `modules/<Module>/src/Infrastructure/Seeder/`, and nothing discovers them
 * automatically. That is a deliberate architecture, but it left Laravel's
 * default entry point pointing at a class that did not exist, so bare
 * `php artisan db:seed --force` failed with
 * `Target class [DatabaseSeeder] does not exist` on every run — before touching
 * the database, so no amount of migrating or ordering could help. Both Docker
 * certification workflows masked that failure rather than fixing it (M50-13).
 *
 * This class is the missing entry point and nothing more. It owns no data of
 * its own; it names the module seeders and calls them. A new module seeder is
 * added here explicitly — that one line is the price of not having magic
 * discovery, and it is the right price: what the certification gate seeds
 * should be a decision somebody wrote down, not a glob.
 *
 * ## Order
 *
 * Verified against PostgreSQL 16: every one of these seeders succeeds on its
 * own against a freshly migrated database, so there is no hard dependency
 * between them and no ordering constraint to satisfy. The order below is
 * therefore chosen to read in the direction the data flows — platform
 * configuration, then content, then the things you can buy, then money, then
 * what people do afterwards — rather than to work around a dependency that
 * does not exist. Do not read it as a constraint graph.
 *
 * ## Re-runnability
 *
 * Each seeder is safe to run more than once: it either skips what it already
 * created or looks its records up by the unique key the schema already
 * enforces. None of them suppresses a duplicate-key error to get there.
 *
 * ## Not included
 *
 * Nothing. All eleven module seeders are here. If a twelfth is written, add it.
 */
final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            // Platform configuration: settings, flags, templates and prompts
            // that other modules read at runtime.
            AdminSeeder::class,
            NotificationsSeeder::class,
            DefaultPromptSeeder::class,

            // Content: the food catalogue and its nutrition reference data.
            NigerianFoodSeeder::class,
            NutritionItemSeeder::class,

            // Places to buy from: restaurant vendors and the grocery store.
            MarketplaceSeeder::class,
            CommerceSeeder::class,

            // Money: the platform escrow wallet and the demo customer/vendor
            // wallets.
            PaymentsSeeder::class,

            // Engagement: rewards, reviews of the demo vendor, and the support
            // knowledge base.
            LoyaltySeeder::class,
            ReviewsSeeder::class,
            SupportSeeder::class,
        ]);
    }
}
