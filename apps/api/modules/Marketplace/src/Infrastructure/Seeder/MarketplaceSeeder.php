<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Infrastructure\Seeder;

use EruoFood\Marketplace\Domain\Enum\VendorType;
use EruoFood\Marketplace\Domain\Menu\MenuItem;
use EruoFood\Marketplace\Domain\Menu\MenuItemRepository;
use EruoFood\Marketplace\Domain\ValueObject\Address;
use EruoFood\Marketplace\Domain\ValueObject\ContactInfo;
use EruoFood\Marketplace\Domain\ValueObject\GeoLocation;
use EruoFood\Marketplace\Domain\Vendor\Vendor;
use EruoFood\Marketplace\Domain\Vendor\VendorRepository;
use EruoFood\Shared\Domain\Clock;
use EruoFood\Shared\Domain\ValueObject\Money;
use EruoFood\Shared\Domain\ValueObject\Slug;
use Illuminate\Database\Seeder;

/**
 * Seeds a couple of verified Lagos vendors with a small menu so the marketplace
 * demonstrates end-to-end (browse → cart → checkout). Idempotent by slug. Run:
 *   php artisan db:seed --class="EruoFood\Marketplace\Infrastructure\Seeder\MarketplaceSeeder"
 */
final class MarketplaceSeeder extends Seeder
{
    private const OWNER = '00000000-0000-4000-8000-000000000002';

    public function __construct(
        private readonly VendorRepository $vendors,
        private readonly MenuItemRepository $items,
        private readonly Clock $clock,
        private readonly string $currency,
    ) {
    }

    public function run(): void
    {
        $this->seedVendor(
            'Mama Put Kitchen',
            VendorType::Restaurant,
            'african',
            new GeoLocation(6.4550, 3.3841), // Victoria Island, Lagos
            [
                ['Jollof Rice & Chicken', 250000, ['party', 'rice'], 350],
                ['Pounded Yam & Egusi', 300000, ['swallow', 'soup'], 620],
                ['Suya Platter', 350000, ['grill', 'spicy'], 500],
            ],
        );

        $this->seedVendor(
            'Naija Grills',
            VendorType::CloudKitchen,
            'grill',
            new GeoLocation(6.5244, 3.3792), // Lagos mainland
            [
                ['Peppered Goat Meat', 400000, ['grill', 'spicy'], 480],
                ['Grilled Tilapia', 450000, ['fish', 'grill'], 300],
            ],
        );
    }

    /**
     * @param list<array{0: string, 1: int, 2: list<string>, 3: int}> $menu
     */
    private function seedVendor(string $name, VendorType $type, string $category, GeoLocation $geo, array $menu): void
    {
        $slug = Slug::fromTitle($name);
        if ($this->vendors->slugExists((string) $slug)) {
            return;
        }

        $vendor = Vendor::register(
            id: $this->vendors->nextIdentity(),
            ownerUserId: self::OWNER,
            name: $name,
            slug: $slug,
            type: $type,
            category: $category,
            contact: new ContactInfo('+2348000000000', 'hello@example.com'),
            address: new Address('1 Demo Street', 'Lagos', 'Lagos', $geo),
            now: $this->clock->now(),
            autoVerify: true,
        );
        $this->vendors->save($vendor);

        foreach ($menu as [$itemName, $priceMinor, $tags, $calories]) {
            $this->items->save(MenuItem::create(
                id: $this->items->nextIdentity(),
                vendorId: $vendor->id(),
                categoryId: null,
                name: $itemName,
                description: null,
                basePrice: new Money($priceMinor, $this->currency),
                tags: $tags,
                calories: $calories,
            ));
        }
    }
}
