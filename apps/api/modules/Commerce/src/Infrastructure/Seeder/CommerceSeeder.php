<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Infrastructure\Seeder;

use DateTimeImmutable;
use EruoFood\Commerce\Domain\Catalog\Category;
use EruoFood\Commerce\Domain\Catalog\CategoryRepository;
use EruoFood\Commerce\Domain\Catalog\Product;
use EruoFood\Commerce\Domain\Catalog\ProductRepository;
use EruoFood\Commerce\Domain\Enum\CouponType;
use EruoFood\Commerce\Domain\Enum\GroceryDepartment;
use EruoFood\Commerce\Domain\Enum\ProductKind;
use EruoFood\Commerce\Domain\Inventory\InventoryItem;
use EruoFood\Commerce\Domain\Inventory\InventoryItemRepository;
use EruoFood\Commerce\Domain\Inventory\Supplier;
use EruoFood\Commerce\Domain\Inventory\SupplierRepository;
use EruoFood\Commerce\Domain\Inventory\Warehouse;
use EruoFood\Commerce\Domain\Inventory\WarehouseRepository;
use EruoFood\Commerce\Domain\Promotion\Coupon;
use EruoFood\Commerce\Domain\Promotion\CouponRepository;
use EruoFood\Commerce\Domain\Store\Store;
use EruoFood\Commerce\Domain\Store\StoreRepository;
use EruoFood\Commerce\Domain\ValueObject\Address;
use EruoFood\Commerce\Domain\ValueObject\Batch;
use EruoFood\Shared\Domain\ValueObject\Money;
use EruoFood\Shared\Domain\ValueObject\Slug;
use Illuminate\Database\Seeder;

/**
 * Sample commerce data: a verified Lagos store with grocery and general
 * products, a warehouse, a supplier, tracked stock (one batch with an expiry),
 * and a welcome coupon. Idempotent-ish for local/demo use.
 */
final class CommerceSeeder extends Seeder
{
    public function run(): void
    {
        /** @var StoreRepository $stores */
        $stores = $this->app(StoreRepository::class);
        /** @var CategoryRepository $categories */
        $categories = $this->app(CategoryRepository::class);
        /** @var ProductRepository $products */
        $products = $this->app(ProductRepository::class);
        /** @var InventoryItemRepository $inventory */
        $inventory = $this->app(InventoryItemRepository::class);
        /** @var WarehouseRepository $warehouses */
        $warehouses = $this->app(WarehouseRepository::class);
        /** @var SupplierRepository $suppliers */
        $suppliers = $this->app(SupplierRepository::class);
        /** @var CouponRepository $coupons */
        $coupons = $this->app(CouponRepository::class);

        $ownerId = '00000000-0000-0000-0000-0000000000c0';
        $now = new DateTimeImmutable();

        $store = Store::register($stores->nextIdentity(), $ownerId, 'Lagos Fresh Market', Slug::fromTitle('Lagos Fresh Market'), $now, autoVerify: true);
        $store->updateProfile(
            'Lagos Fresh Market',
            'Groceries and everyday essentials delivered across Lagos.',
            null,
            new Address('12 Adeola Odeku St', null, 'Lagos', 'Lagos', '101241', 'NG'),
            'hello@lagosfresh.example',
            '+2348000000000',
        );
        $stores->save($store);

        $produce = Category::create($categories->nextIdentity(), 'Fresh Produce', Slug::fromTitle('Fresh Produce'), ProductKind::Grocery, GroceryDepartment::Produce);
        $pantry = Category::create($categories->nextIdentity(), 'Pantry', Slug::fromTitle('Pantry'), ProductKind::Grocery, GroceryDepartment::Pantry);
        $household = Category::create($categories->nextIdentity(), 'Household', Slug::fromTitle('Household'), ProductKind::General, null);
        foreach ([$produce, $pantry, $household] as $category) {
            $categories->save($category);
        }

        $warehouse = Warehouse::create($warehouses->nextIdentity(), 'Ikeja DC', 'IKJ', new Address('KM 8 Airport Rd', null, 'Ikeja', 'Lagos', null, 'NG'));
        $warehouses->save($warehouse);

        $supplier = Supplier::create($suppliers->nextIdentity(), 'Naija Farms Ltd', 'Ada', 'sales@naijafarms.example', '+2348011111111');
        $suppliers->save($supplier);

        $catalogue = [
            [$produce->id(), GroceryDepartment::Produce, ProductKind::Grocery, 'Ripe Plantain (bunch)', 180000, ['fruit', 'fresh'], true],
            [$pantry->id(), GroceryDepartment::Pantry, ProductKind::Grocery, 'Ofada Rice 5kg', 950000, ['staple', 'rice'], false],
            [$pantry->id(), GroceryDepartment::Pantry, ProductKind::Grocery, 'Palm Oil 2L', 420000, ['oil', 'cooking'], false],
            [$household->id(), null, ProductKind::General, 'Dish Soap 1L', 150000, ['cleaning'], false],
        ];

        foreach ($catalogue as [$categoryId, $department, $kind, $name, $priceMinor, $tags, $featured]) {
            $product = Product::create(
                id: $products->nextIdentity(),
                storeId: $store->id(),
                categoryId: $categoryId,
                name: $name,
                slug: $this->uniqueSlug($products, $name),
                kind: $kind,
                department: $department,
                description: null,
                basePrice: new Money($priceMinor, 'NGN'),
                tags: $tags,
                autoPublish: true,
            );
            if ($featured) {
                $product->setFeatured(true);
            }
            $products->save($product);

            $item = InventoryItem::open($inventory->nextIdentity(), $product->id(), null, $warehouse->id(), $supplier->id(), 0, 10);
            $item->receive(120, new Batch('B-'.substr($product->id(), 0, 8), 120, $now->modify('+30 days'), $now));
            $inventory->save($item);
        }

        if (! $coupons->codeExists('WELCOME10')) {
            $coupons->save(Coupon::create($coupons->nextIdentity(), 'WELCOME10', CouponType::Percentage, 10, 500000, 1000, $now->modify('+90 days')));
        }
    }

    /** @template T @param class-string<T> $abstract @return T */
    private function app(string $abstract): object
    {
        /** @var T $resolved */
        $resolved = app($abstract);

        return $resolved;
    }

    private function uniqueSlug(ProductRepository $products, string $name): Slug
    {
        $base = Slug::fromTitle($name);
        if (! $products->slugExists($base->value)) {
            return $base;
        }
        for ($i = 2; $i <= 100; $i++) {
            $candidate = new Slug($base->value.'-'.$i);
            if (! $products->slugExists($candidate->value)) {
                return $candidate;
            }
        }

        return new Slug($base->value.'-'.substr(bin2hex(random_bytes(3)), 0, 5));
    }
}
