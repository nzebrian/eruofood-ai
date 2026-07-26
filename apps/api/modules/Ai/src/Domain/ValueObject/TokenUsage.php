<?php

declare(strict_types=1);

namespace EruoFood\Ai\Domain\ValueObject;

use EruoFood\Shared\Domain\Exception\InvalidArgumentException;

/**
 * Token accounting for a single completion.
 *
 * Populated from the provider's usage metadata and fed into the cost calculator
 * and the usage ledger. Immutable — a completed call's token counts never change.
 */
final readonly class TokenUsage
{
    public function __construct(
        public int $inputTokens,
        public int $outputTokens,
    ) {
        if ($inputTokens < 0 || $outputTokens < 0) {
            throw new InvalidArgumentException('Token counts cannot be negative.');
        }
    }

    public static function zero(): self
    {
        return new self(0, 0);
    }

    public function total(): int
    {
        return $this->inputTokens + $this->outputTokens;
    }

    /** @return array{input: int, output: int, total: int} */
    public function toArray(): array
    {
        return [
            'input' => $this->inputTokens,
            'output' => $this->outputTokens,
            'total' => $this->total(),
        ];
    }
}
