<?php

declare(strict_types=1);

namespace EruoFood\Ai\Infrastructure\Provider;

use EruoFood\Ai\Application\DTO\AiCompletionRequest;
use EruoFood\Ai\Application\DTO\AiCompletionResult;
use EruoFood\Ai\Application\Port\AiProvider;
use EruoFood\Ai\Domain\Enum\AiProviderName;
use EruoFood\Ai\Domain\Exception\AiGenerationFailed;
use EruoFood\Ai\Domain\ValueObject\TokenUsage;
use Illuminate\Http\Client\Factory as HttpClient;
use Throwable;

/**
 * Shared adapter for OpenAI-compatible `/chat/completions` APIs.
 *
 * Both the OpenAI cloud API and self-hosted local runtimes (Ollama, LM Studio,
 * vLLM) speak this dialect, so a single request/response mapping serves both —
 * subclasses only supply their provider identity. The system prompt is sent as
 * the first `system` message, unlike Anthropic where it is a separate field.
 */
abstract readonly class OpenAiCompatibleProvider implements AiProvider
{
    /**
     * @param array{api_key?: ?string, base_url?: string, model?: string} $config
     */
    public function __construct(
        protected HttpClient $http,
        protected array $config,
        protected int $timeout,
    ) {
    }

    abstract public function name(): AiProviderName;

    public function isConfigured(): bool
    {
        return ! empty($this->config['api_key']) && ! empty($this->config['base_url']);
    }

    public function complete(AiCompletionRequest $request): AiCompletionResult
    {
        $model = $request->model ?? ($this->config['model'] ?? 'gpt-4o');

        $messages = [['role' => 'system', 'content' => $request->system]];
        foreach ($request->messages as $message) {
            $messages[] = $message->toArray();
        }

        try {
            $response = $this->http
                ->baseUrl((string) ($this->config['base_url'] ?? ''))
                ->withToken((string) ($this->config['api_key'] ?? ''))
                ->timeout($this->timeout)
                ->post('/chat/completions', [
                    'model' => $model,
                    'max_tokens' => $request->maxTokens,
                    'temperature' => $request->temperature,
                    'messages' => $messages,
                ]);
        } catch (Throwable $e) {
            throw AiGenerationFailed::because($this->name()->value.' request failed: '.$e->getMessage(), $e);
        }

        if ($response->failed()) {
            throw AiGenerationFailed::because($this->name()->value.' returned HTTP '.$response->status());
        }

        $text = (string) $response->json('choices.0.message.content', '');
        if ($text === '') {
            throw AiGenerationFailed::because($this->name()->value.' returned an empty completion');
        }

        return new AiCompletionResult(
            text: $text,
            tokens: new TokenUsage(
                (int) $response->json('usage.prompt_tokens', 0),
                (int) $response->json('usage.completion_tokens', 0),
            ),
            provider: $this->name(),
            model: $model,
            finishReason: (string) $response->json('choices.0.finish_reason', 'stop'),
        );
    }
}
