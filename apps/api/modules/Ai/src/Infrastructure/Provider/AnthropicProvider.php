<?php

declare(strict_types=1);

namespace EruoFood\Ai\Infrastructure\Provider;

use EruoFood\Ai\Application\DTO\AiCompletionRequest;
use EruoFood\Ai\Application\DTO\AiCompletionResult;
use EruoFood\Ai\Application\Port\AiProvider;
use EruoFood\Ai\Domain\Enum\AiProviderName;
use EruoFood\Ai\Domain\Exception\AiGenerationFailed;
use EruoFood\Ai\Domain\ValueObject\AiMessage;
use EruoFood\Ai\Domain\ValueObject\TokenUsage;
use Illuminate\Http\Client\Factory as HttpClient;
use Throwable;

/**
 * Anthropic (Claude) adapter, talking to the Messages API over HTTPS.
 *
 * Uses Laravel's HTTP client rather than an external SDK so the module has no
 * extra Composer dependency. The system prompt is a top-level field and only
 * user/assistant turns go in `messages`, matching the Messages API contract.
 */
final readonly class AnthropicProvider implements AiProvider
{
    /**
     * @param array{api_key?: ?string, base_url?: string, version?: string, model?: string} $config
     */
    public function __construct(
        private HttpClient $http,
        private array $config,
        private int $timeout,
    ) {
    }

    public function name(): AiProviderName
    {
        return AiProviderName::Anthropic;
    }

    public function isConfigured(): bool
    {
        return ! empty($this->config['api_key']);
    }

    public function complete(AiCompletionRequest $request): AiCompletionResult
    {
        $model = $request->model ?? ($this->config['model'] ?? 'claude-opus-5');

        try {
            $response = $this->http
                ->baseUrl((string) ($this->config['base_url'] ?? 'https://api.anthropic.com'))
                ->withHeaders([
                    'x-api-key' => (string) ($this->config['api_key'] ?? ''),
                    'anthropic-version' => (string) ($this->config['version'] ?? '2023-06-01'),
                    'content-type' => 'application/json',
                ])
                ->timeout($this->timeout)
                ->post('/v1/messages', [
                    'model' => $model,
                    'max_tokens' => $request->maxTokens,
                    'temperature' => $request->temperature,
                    'system' => $request->system,
                    'messages' => array_map(
                        static fn (AiMessage $m): array => $m->toArray(),
                        $request->messages,
                    ),
                ]);
        } catch (Throwable $e) {
            throw AiGenerationFailed::because('Anthropic request failed: '.$e->getMessage(), $e);
        }

        if ($response->failed()) {
            throw AiGenerationFailed::because('Anthropic returned HTTP '.$response->status());
        }

        $text = (string) $response->json('content.0.text', '');
        if ($text === '') {
            throw AiGenerationFailed::because('Anthropic returned an empty completion');
        }

        return new AiCompletionResult(
            text: $text,
            tokens: new TokenUsage(
                (int) $response->json('usage.input_tokens', 0),
                (int) $response->json('usage.output_tokens', 0),
            ),
            provider: AiProviderName::Anthropic,
            model: $model,
            finishReason: (string) $response->json('stop_reason', 'stop'),
        );
    }
}
