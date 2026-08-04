<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Domain\Read;

use EruoFood\Shared\Domain\Paginated;

/**
 * The port through which the Public API reads catalogue data (foods, recipes)
 * for its external endpoints. Implemented in Infrastructure by an adapter over
 * the Catalog context's read side — the Public API never queries Catalog tables
 * or imports its domain directly here, keeping this a façade over a published
 * read surface.
 */
interface CatalogReadPort
{
    /**
     * @return Paginated<FoodResource>
     */
    public function foods(ResourceQuery $query): Paginated;

    public function food(string $slug): ?FoodResource;

    /**
     * @return Paginated<RecipeResource>
     */
    public function recipes(ResourceQuery $query): Paginated;

    public function recipe(string $slug): ?RecipeResource;
}
