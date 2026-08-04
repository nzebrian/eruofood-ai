<?php

declare(strict_types=1);

namespace EruoFood\Catalog\Application\Input;

use EruoFood\Catalog\Domain\Enum\FoodRegion;
use EruoFood\Catalog\Domain\ValueObject\LocalName;
use EruoFood\Catalog\Domain\ValueObject\NutritionalInfo;

/** Validated input for creating/updating a Food. Built by the FormRequest. */
final readonly class FoodInput
{
    /**
     * @param list<string> $states
     * @param list<LocalName> $localNames
     * @param list<string> $tags
     */
    public function __construct(
        public string $name,
        public string $categoryId,
        public FoodRegion $region,
        public ?string $description = null,
        public array $states = [],
        public array $localNames = [],
        public ?NutritionalInfo $nutrition = null,
        public array $tags = [],
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $localNames = array_map(
            static fn (array $ln): LocalName => LocalName::fromArray($ln),
            $data['local_names'] ?? [],
        );

        return new self(
            name: (string) $data['name'],
            categoryId: (string) $data['category_id'],
            region: FoodRegion::from((string) $data['region']),
            description: isset($data['description']) ? (string) $data['description'] : null,
            states: array_values(array_map('strval', $data['states'] ?? [])),
            localNames: array_values($localNames),
            nutrition: isset($data['nutrition']) && is_array($data['nutrition'])
                ? NutritionalInfo::fromArray($data['nutrition'])
                : null,
            tags: array_values(array_map('strval', $data['tags'] ?? [])),
        );
    }
}
