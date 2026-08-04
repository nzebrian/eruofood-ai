<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Domain\Catalog;

use EruoFood\Commerce\Domain\Enum\GroceryDepartment;
use EruoFood\Commerce\Domain\Enum\ProductKind;

/** Immutable filter/sort criteria for the public product search. */
final readonly class ProductSearchCriteria
{
    public function __construct(
        public ?string $term = null,
        public ?string $storeId = null,
        public ?string $categoryId = null,
        public ?ProductKind $kind = null,
        public ?GroceryDepartment $department = null,
        public ?int $minPriceMinor = null,
        public ?int $maxPriceMinor = null,
        public bool $featuredOnly = false,
        public string $sort = 'relevance', // relevance|price_asc|price_desc|rating|newest
    ) {
    }
}
