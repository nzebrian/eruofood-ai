<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Domain\Read;

/** Public view of a grocery/commerce product. */
final readonly class ProductResource
{
    /** @param list<string> $images */
    public function __construct(
        public string $id,
        public string $slug,
        public string $name,
        public string $kind,
        public ?string $department,
        public ?string $description,
        public int $priceMinor,
        public string $currency,
        public ?string $categoryId,
        public array $images,
    ) {
    }
}
