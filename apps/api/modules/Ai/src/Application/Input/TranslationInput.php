<?php

declare(strict_types=1);

namespace EruoFood\Ai\Application\Input;

/** Validated input for recipe translation. */
final readonly class TranslationInput
{
    public function __construct(
        public string $content,
        public string $targetLanguage,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            content: (string) $data['content'],
            targetLanguage: (string) $data['target_language'],
        );
    }
}
