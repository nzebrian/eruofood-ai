<?php

declare(strict_types=1);

use EruoFood\Ai\Domain\Enum\AiProviderName;
use EruoFood\Ai\Domain\ValueObject\TokenUsage;
use EruoFood\Ai\Infrastructure\Cost\TableCostCalculator;

it('computes cost from the per-million pricing table', function (): void {
    $calc = new TableCostCalculator([
        'anthropic/claude-opus-5' => ['input' => 5.0, 'output' => 25.0],
        'default' => ['input' => 3.0, 'output' => 15.0],
    ]);

    // 1M input @ $5 + 1M output @ $25 = $30
    $cost = $calc->costFor(AiProviderName::Anthropic, 'claude-opus-5', new TokenUsage(1_000_000, 1_000_000));

    expect($cost)->toBe(30.0);
});

it('falls back to the default rate for unknown models', function (): void {
    $calc = new TableCostCalculator(['default' => ['input' => 2.0, 'output' => 10.0]]);

    // 500k input @ $2 = $1.00; 100k output @ $10 = $1.00 => $2.00
    $cost = $calc->costFor(AiProviderName::OpenAi, 'gpt-unknown', new TokenUsage(500_000, 100_000));

    expect($cost)->toBe(2.0);
});

it('returns zero when no pricing is configured', function (): void {
    $calc = new TableCostCalculator([]);

    expect($calc->costFor(AiProviderName::Local, 'llama', new TokenUsage(1000, 1000)))->toBe(0.0);
});
