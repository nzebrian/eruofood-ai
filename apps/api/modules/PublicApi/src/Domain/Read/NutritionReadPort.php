<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Domain\Read;

use EruoFood\Shared\Domain\Paginated;

/** Port for reading nutrition data, implemented over the Nutrition context. */
interface NutritionReadPort
{
    /** @return Paginated<NutritionResource> */
    public function items(ResourceQuery $query): Paginated;

    public function item(string $slug): ?NutritionResource;
}
