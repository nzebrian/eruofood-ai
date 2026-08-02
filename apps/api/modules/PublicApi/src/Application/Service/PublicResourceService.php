<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Application\Service;

use EruoFood\PublicApi\Domain\Exception\PublicApiNotFound;
use EruoFood\PublicApi\Domain\Read\CatalogReadPort;
use EruoFood\PublicApi\Domain\Read\FoodResource;
use EruoFood\PublicApi\Domain\Read\RecipeResource;
use EruoFood\PublicApi\Domain\Read\ResourceQuery;
use EruoFood\Shared\Domain\Paginated;

/**
 * Serves the public data resources by delegating to read ports over the owning
 * contexts. The Public API never exposes an internal endpoint or entity — it
 * reads through these ports and returns its own transformed resources.
 */
final readonly class PublicResourceService
{
    public function __construct(private CatalogReadPort $catalog)
    {
    }

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
}
