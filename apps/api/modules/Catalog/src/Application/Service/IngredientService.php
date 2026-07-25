<?php

declare(strict_types=1);

namespace EruoFood\Catalog\Application\Service;

use EruoFood\Catalog\Domain\Exception\CatalogNotFound;
use EruoFood\Catalog\Domain\Ingredient\Ingredient;
use EruoFood\Catalog\Domain\Ingredient\IngredientRepository;
use EruoFood\Catalog\Domain\ValueObject\LocalName;
use EruoFood\Catalog\Domain\ValueObject\NutritionalInfo;
use EruoFood\Shared\Domain\Paginated;

/** Ingredient management (admin) + search (public). */
final readonly class IngredientService
{
    public function __construct(private IngredientRepository $ingredients)
    {
    }

    /**
     * @return Paginated<Ingredient>
     */
    public function search(?string $term, int $page, int $perPage): Paginated
    {
        return $this->ingredients->search($term, max(1, $page), $this->clampPerPage($perPage));
    }

    /**
     * @param list<array<string, mixed>> $localNames
     * @param array<string, mixed>|null $nutrition
     */
    public function create(string $name, ?string $description, array $localNames, ?array $nutrition): Ingredient
    {
        $ingredient = Ingredient::create(
            $this->ingredients->nextIdentity(),
            $name,
            $description,
            $this->mapLocalNames($localNames),
            $nutrition !== null ? NutritionalInfo::fromArray($nutrition) : null,
        );
        $this->ingredients->save($ingredient);

        return $ingredient;
    }

    /**
     * @param list<array<string, mixed>> $localNames
     * @param array<string, mixed>|null $nutrition
     */
    public function update(string $id, string $name, ?string $description, array $localNames, ?array $nutrition): Ingredient
    {
        $ingredient = $this->load($id);
        $ingredient->update(
            $name,
            $description,
            $this->mapLocalNames($localNames),
            $nutrition !== null ? NutritionalInfo::fromArray($nutrition) : null,
        );
        $this->ingredients->save($ingredient);

        return $ingredient;
    }

    public function delete(string $id): void
    {
        $this->load($id);
        $this->ingredients->delete($id);
    }

    /**
     * @param list<array<string, mixed>> $localNames
     * @return list<LocalName>
     */
    private function mapLocalNames(array $localNames): array
    {
        return array_values(array_map(static fn (array $ln): LocalName => LocalName::fromArray($ln), $localNames));
    }

    private function clampPerPage(int $perPage): int
    {
        return min(60, max(1, $perPage));
    }

    private function load(string $id): Ingredient
    {
        return $this->ingredients->findById($id) ?? throw CatalogNotFound::of('ingredient', $id);
    }
}
