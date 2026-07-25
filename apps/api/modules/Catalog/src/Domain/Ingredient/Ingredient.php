<?php

declare(strict_types=1);

namespace EruoFood\Catalog\Domain\Ingredient;

use EruoFood\Catalog\Domain\ValueObject\LocalName;
use EruoFood\Catalog\Domain\ValueObject\NutritionalInfo;
use EruoFood\Shared\Domain\ValueObject\Slug;

/** Ingredient aggregate — a reusable, searchable ingredient reference. */
final class Ingredient
{
    /**
     * @param list<LocalName> $localNames
     */
    private function __construct(
        private readonly string $id,
        private string $name,
        private Slug $slug,
        private ?string $description,
        private array $localNames,
        private ?NutritionalInfo $nutritionPer100g,
    ) {
    }

    /**
     * @param list<LocalName> $localNames
     */
    public static function create(
        string $id,
        string $name,
        ?string $description = null,
        array $localNames = [],
        ?NutritionalInfo $nutritionPer100g = null,
    ): self {
        return new self($id, $name, Slug::fromTitle($name), $description, $localNames, $nutritionPer100g);
    }

    /**
     * @param list<LocalName> $localNames
     */
    public static function reconstitute(
        string $id,
        string $name,
        Slug $slug,
        ?string $description,
        array $localNames,
        ?NutritionalInfo $nutritionPer100g,
    ): self {
        return new self($id, $name, $slug, $description, $localNames, $nutritionPer100g);
    }

    /**
     * @param list<LocalName> $localNames
     */
    public function update(string $name, ?string $description, array $localNames, ?NutritionalInfo $nutrition): void
    {
        $this->name = $name;
        $this->slug = Slug::fromTitle($name);
        $this->description = $description;
        $this->localNames = $localNames;
        $this->nutritionPer100g = $nutrition;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function slug(): Slug
    {
        return $this->slug;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    /** @return list<LocalName> */
    public function localNames(): array
    {
        return $this->localNames;
    }

    public function nutritionPer100g(): ?NutritionalInfo
    {
        return $this->nutritionPer100g;
    }
}
