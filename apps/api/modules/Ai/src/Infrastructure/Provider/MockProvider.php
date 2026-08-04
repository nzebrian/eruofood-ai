<?php

declare(strict_types=1);

namespace EruoFood\Ai\Infrastructure\Provider;

use EruoFood\Ai\Application\DTO\AiCompletionRequest;
use EruoFood\Ai\Application\DTO\AiCompletionResult;
use EruoFood\Ai\Application\Port\AiProvider;
use EruoFood\Ai\Domain\Enum\AiProviderName;
use EruoFood\Ai\Domain\ValueObject\TokenUsage;

/**
 * A deterministic, offline provider — the linchpin of testability.
 *
 * It makes no network calls and returns stable output derived from the request,
 * so the entire AI engine (gateway, caching, feature services, controllers) can
 * be exercised in unit/feature tests and local development with zero credentials
 * and zero cost. When a prompt asks for JSON it returns a valid JSON object;
 * otherwise it returns plain prose, mirroring how the real providers behave for
 * the two response styles the engine uses.
 */
final readonly class MockProvider implements AiProvider
{
    public function __construct(private string $model = 'mock-1')
    {
    }

    public function name(): AiProviderName
    {
        return AiProviderName::Mock;
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function complete(AiCompletionRequest $request): AiCompletionResult
    {
        $lastUser = $this->lastUserMessage($request);
        $text = $this->wantsJson($request) ? $this->jsonAnswer($lastUser) : $this->proseAnswer($lastUser);

        $inputTokens = $this->countWords($request->system.' '.$lastUser);
        $outputTokens = $this->countWords($text);

        return new AiCompletionResult(
            text: $text,
            tokens: new TokenUsage($inputTokens, $outputTokens),
            provider: AiProviderName::Mock,
            model: $request->model ?? $this->model,
            finishReason: 'stop',
        );
    }

    private function wantsJson(AiCompletionRequest $request): bool
    {
        $haystack = strtolower($request->system.' '.$this->lastUserMessage($request));

        return str_contains($haystack, 'json');
    }

    private function jsonAnswer(string $prompt): string
    {
        // A superset of the keys the various structured features assert on, so a
        // single mock satisfies recipe generation, substitutions and meal ideas.
        return (string) json_encode([
            'title' => 'Mock Jollof Rice',
            'summary' => 'A deterministic mock recipe generated offline for testing.',
            'servings' => 4,
            'difficulty' => 'medium',
            'ingredients' => ['2 cups long-grain rice', '3 ripe tomatoes', '1 onion'],
            'steps' => ['Blend the tomatoes and onion.', 'Fry the base, add rice and stock, simmer.'],
            'tips' => ['Use parboiled rice for the best texture.'],
            'suggestions' => ['Mock meal suggestion one', 'Mock meal suggestion two'],
            'substitutions' => ['Swap butter for shea/vegetable oil'],
            'echo' => mb_substr($prompt, 0, 60),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    private function proseAnswer(string $prompt): string
    {
        return "This is a deterministic mock response for testing.\n\n"
            .'You asked: '.mb_substr(trim($prompt), 0, 120);
    }

    private function lastUserMessage(AiCompletionRequest $request): string
    {
        $messages = $request->messages;
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if ($messages[$i]->role->value === 'user') {
                return $messages[$i]->content;
            }
        }

        return '';
    }

    private function countWords(string $text): int
    {
        return max(1, str_word_count($text));
    }
}
