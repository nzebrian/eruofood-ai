<?php

declare(strict_types=1);

use EruoFood\Ai\Application\DTO\AiCompletionRequest;
use EruoFood\Ai\Domain\Enum\AiProviderName;
use EruoFood\Ai\Domain\ValueObject\AiMessage;
use EruoFood\Ai\Infrastructure\Provider\MockProvider;

function mockRequest(string $system, string $user): AiCompletionRequest
{
    return new AiCompletionRequest($system, [AiMessage::user($user)], 512, 0.0);
}

it('is always configured and identifies as the mock provider', function (): void {
    $provider = new MockProvider();

    expect($provider->isConfigured())->toBeTrue()
        ->and($provider->name())->toBe(AiProviderName::Mock);
});

it('returns valid JSON when the prompt asks for JSON', function (): void {
    $result = (new MockProvider())->complete(
        mockRequest('Respond ONLY with a single valid JSON object.', 'Make jollof'),
    );

    $decoded = json_decode($result->text, true);
    expect($decoded)->toBeArray()->toHaveKeys(['title', 'ingredients', 'steps']);
});

it('returns plain prose when JSON is not requested', function (): void {
    $result = (new MockProvider())->complete(mockRequest('You are a helpful cook.', 'How do I boil rice?'));

    expect(json_decode($result->text, true))->toBeNull()
        ->and($result->text)->toContain('mock response');
});

it('reports deterministic non-zero token usage', function (): void {
    $result = (new MockProvider())->complete(mockRequest('system', 'hello world'));

    expect($result->tokens->inputTokens)->toBeGreaterThan(0)
        ->and($result->tokens->outputTokens)->toBeGreaterThan(0)
        ->and($result->finishReason)->toBe('stop');
});
