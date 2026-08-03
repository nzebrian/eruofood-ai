<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Application\Service;

use EruoFood\PublicApi\Domain\Exception\PublicApiNotFound;
use EruoFood\PublicApi\Domain\Read\CatalogReadPort;
use EruoFood\PublicApi\Domain\Read\CommerceReadPort;
use EruoFood\PublicApi\Domain\Read\FoodResource;
use EruoFood\PublicApi\Domain\Read\MenuItemResource;
use EruoFood\PublicApi\Domain\Read\NutritionReadPort;
use EruoFood\PublicApi\Domain\Read\NutritionResource;
use EruoFood\PublicApi\Domain\Read\ProductCategoryResource;
use EruoFood\PublicApi\Domain\Read\ProductResource;
use EruoFood\PublicApi\Domain\Read\RecipeResource;
use EruoFood\PublicApi\Domain\Read\ResourceQuery;
use EruoFood\PublicApi\Domain\Read\RestaurantReadPort;
use EruoFood\PublicApi\Domain\Read\RestaurantResource;
use EruoFood\Shared\Domain\Paginated;

/**
 * Serves the public data resources by delegating to read ports over the owning
 * contexts (Catalog, Marketplace, Commerce, Nutrition). The Public API never
 * exposes an internal endpoint or entity — it reads through these ports and
 * returns its own transformed resources, so the external contract stays
 * decoupled from every source context's internal model.
 */
final readonly class PublicResourceService
{
    public function __construct(
        private CatalogReadPort $catalog,
        private RestaurantReadPort $restaurants,
        private CommerceReadPort $commerce,
        private NutritionReadPort $nutrition,
    ) {
    }

    // ---- Catalog: foods & recipes ------------------------------------------

    /**
     * @return Paginated<FoodResource>
     */
    public function foods(ResourceQuery $query): Paginated
    {
        return $this->catalog->foods($query);
    }

    public function food(string $slug): FoodResource
    {
        return $this->catalog->food($slug) ?? throw PublicApiNotFound::of('food', $slug);
    }

    /**
     * @return Paginated<RecipeResource>
     */
    public function recipes(ResourceQuery $query): Paginated
    {
        return $this->catalog->recipes($query);
    }

    public function recipe(string $slug): RecipeResource
    {
        return $this->catalog->recipe($slug) ?? throw PublicApiNotFound::of('recipe', $slug);
    }

    // ---- Marketplace: restaurants & menus ----------------------------------

    /**
     * @return Paginated<RestaurantResource>
     */
    public function restaurants(ResourceQuery $query): Paginated
    {
        return $this->restaurants->restaurants($query);
    }

    public function restaurant(string $slug): RestaurantResource
    {
        return $this->restaurants->restaurant($slug) ?? throw PublicApiNotFound::of('restaurant', $slug);
    }

    /**
     * The menu of a restaurant, addressed by its resource id. Only available
     * items of tradeable vendors are exposed (enforced by the read port).
     *
     * @return list<MenuItemResource>
     */
    public function restaurantMenu(string $restaurantId): array
    {
        return $this->restaurants->menu($restaurantId);
    }

    // ---- Commerce: products & categories -----------------------------------

    /**
     * @return Paginated<ProductResource>
     */
    public function products(ResourceQuery $query): Paginated
    {
        return $this->commerce->products($query);
    }

    public function product(string $slug): ProductResource
    {
        return $this->commerce->product($slug) ?? throw PublicApiNotFound::of('product', $slug);
    }

    /**
     * @return list<ProductCategoryResource>
     */
    public function productCategories(): array
    {
        return $this->commerce->categories();
    }

    // ---- Nutrition ----------------------------------------------------------

    /**
     * @return Paginated<NutritionResource>
     */
    public function nutritionItems(ResourceQuery $query): Paginated
    {
        return $this->nutrition->items($query);
    }

    public function nutritionItem(string $id): NutritionResource
    {
        return $this->nutrition->item($id) ?? throw PublicApiNotFound::of('nutrition item', $id);
    }
}
