<?php

declare(strict_types=1);

namespace EruoFood\Catalog\Domain\ValueObject;

use EruoFood\Shared\Domain\Exception\InvalidArgumentException;

/** One step in the step-by-step cooking instructions. */
final readonly class CookingStep
{
    public function __construct(
        public int $order,
        public string $instruction,
        public ?string $imagePath = null,
        public ?int $durationMinutes = null,
    ) {
        if (trim($instruction) === '') {
            throw new InvalidArgumentException('A cooking step must have an instruction.');
        }
        if ($order < 1) {
            throw new InvalidArgumentException('Step order must be positive.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'order' => $this->order,
            'instruction' => $this->instruction,
            'image_path' => $this->imagePath,
            'duration_minutes' => $this->durationMinutes,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            order: (int) ($data['order'] ?? 1),
            instruction: (string) ($data['instruction'] ?? ''),
            imagePath: isset($data['image_path']) ? (string) $data['image_path'] : null,
            durationMinutes: isset($data['duration_minutes']) ? (int) $data['duration_minutes'] : null,
        );
    }
}
