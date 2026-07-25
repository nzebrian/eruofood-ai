<?php

declare(strict_types=1);

namespace EruoFood\Catalog\Domain\ValueObject;

use EruoFood\Catalog\Domain\Enum\MeasurementUnit;
use EruoFood\Shared\Domain\Exception\InvalidArgumentException;

/**
 * A line item in a recipe: an ingredient (optionally linked to the Ingredient
 * catalogue) with its quantity and an optional preparation note.
 */
final readonly class RecipeIngredient
{
    public function __construct(
        public string $name,
        public Measurement $quantity,
        public ?string $ingredientId = null,
        public ?string $note = null,
    ) {
        if (trim($name) === '') {
            throw new InvalidArgumentException('Recipe ingredient name is required.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'amount' => $this->quantity->amount,
            'unit' => $this->quantity->unit->value,
            'ingredient_id' => $this->ingredientId,
            'note' => $this->note,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            name: (string) ($data['name'] ?? ''),
            quantity: new Measurement(
                (float) ($data['amount'] ?? 0),
                MeasurementUnit::from((string) ($data['unit'] ?? 'piece')),
            ),
            ingredientId: isset($data['ingredient_id']) ? (string) $data['ingredient_id'] : null,
            note: isset($data['note']) ? (string) $data['note'] : null,
        );
    }
}
