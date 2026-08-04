<?php

declare(strict_types=1);

namespace EruoFood\Ai\Application\DTO;

/**
 * The output of a one-shot AI feature: either structured `data` (parsed JSON) or
 * plain `text`, always paired with the completion `meta` (provider, model,
 * tokens, cache flag) for transparency in the API response.
 */
final readonly class GeneratedContent
{
    /**
     * @param array<mixed> $data structured content; [] for text-only features
     */
    public function __construct(
        public array $data,
        public ?string $text,
        public AiCompletionResult $meta,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function structured(array $data, AiCompletionResult $meta): self
    {
        return new self($data, null, $meta);
    }

    public static function fromText(string $text, AiCompletionResult $meta): self
    {
        return new self([], $text, $meta);
    }
}
