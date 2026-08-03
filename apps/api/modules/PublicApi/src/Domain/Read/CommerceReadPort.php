<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Domain\Read;

use EruoFood\Shared\Domain\Paginated;

/** Port for reading products + categories, implemented over the Commerce context. */
interface CommerceReadPort
{
    /** @return Paginated<ProductResource> */
    public function products(ResourceQuery $query): Paginated;

    public function product(string $slug): ?ProductResource;

    /** @return list<ProductCategoryResource> */
    public function categories(): array;
}
