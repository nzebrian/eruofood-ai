<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Domain\Read;

/** Public view of a product category. */
final readonly class ProductCategoryResource
{
    public function __construct(
        public string $id,
        public string $slug,
        public string $name,
        public string $kind,
        public ?string $parentId,
        public int $sortOrder,
    ) {
    }
}
