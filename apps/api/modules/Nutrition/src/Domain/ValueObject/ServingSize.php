<?php

declare(strict_types=1);

namespace EruoFood\Nutrition\Domain\ValueObject;

use EruoFood\Shared\Domain\Exception\InvalidArgumentException;

/**
 * The reference serving a {@see \EruoFood\Nutrition\Domain\Item\NutritionItem}'s
 * facts describe — a human label (e.g. "1 cup, cooked") plus its weight in grams
 * so portions can be scaled numerically.
 */
final readonly class ServingSize
{
    public function __construct(
        public string $label,
        public float $grams,
    ) {
        if ($grams <= 0) {
            throw new InvalidArgumentException('Serving weight must be greater than zero.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['label' => $this->label, 'grams' => round($this->grams, 2)];
    }
}
