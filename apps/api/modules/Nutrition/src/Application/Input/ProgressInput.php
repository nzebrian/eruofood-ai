<?php

declare(strict_types=1);

namespace EruoFood\Nutrition\Application\Input;

/** Validated input for recording a progress measurement. */
final readonly class ProgressInput
{
    public function __construct(
        public string $date,
        public float $weightKg,
        public ?string $note,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            date: (string) $data['date'],
            weightKg: (float) $data['weight_kg'],
            note: isset($data['note']) ? (string) $data['note'] : null,
        );
    }
}
