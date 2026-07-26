<?php

declare(strict_types=1);

namespace EruoFood\Ai\Application\Input;

/** Validated input for cooking tips generation. */
final readonly class CookingTipsInput
{
    public function __construct(
        public string $topic,
        public ?string $skillLevel,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            topic: (string) $data['topic'],
            skillLevel: isset($data['skill_level']) ? (string) $data['skill_level'] : null,
        );
    }
}
