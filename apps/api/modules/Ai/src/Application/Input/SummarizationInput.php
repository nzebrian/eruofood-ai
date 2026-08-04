<?php

declare(strict_types=1);

namespace EruoFood\Ai\Application\Input;

/** Validated input for recipe summarization. */
final readonly class SummarizationInput
{
    public function __construct(
        public string $content,
        public ?string $maxWords,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            content: (string) $data['content'],
            maxWords: isset($data['max_words']) ? (string) (int) $data['max_words'] : null,
        );
    }
}
