<?php

declare(strict_types=1);

namespace EruoFood\Ai\Application\DTO;

use EruoFood\Ai\Domain\Enum\AiProviderName;
use EruoFood\Ai\Domain\ValueObject\TokenUsage;

/**
 * The normalised result of a completion, regardless of which provider produced
 * it. Providers map their raw response into this shape; the gateway, cache and
 * usage ledger all consume it.
 */
final readonly class AiCompletionResult
{
    public function __construct(
        public string $text,
        public TokenUsage $tokens,
        public AiProviderName $provider,
        public string $model,
        public string $finishReason,
        public bool $cached = false,
    ) {
    }

    /** A copy flagged as served from cache (tokens/cost still attributed to the ledger). */
    public function servedFromCache(): self
    {
        return new self($this->text, $this->tokens, $this->provider, $this->model, $this->finishReason, true);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'input_tokens' => $this->tokens->inputTokens,
            'output_tokens' => $this->tokens->outputTokens,
            'provider' => $this->provider->value,
            'model' => $this->model,
            'finish_reason' => $this->finishReason,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['text'],
            new TokenUsage((int) $data['input_tokens'], (int) $data['output_tokens']),
            AiProviderName::from((string) $data['provider']),
            (string) $data['model'],
            (string) $data['finish_reason'],
        );
    }
}
