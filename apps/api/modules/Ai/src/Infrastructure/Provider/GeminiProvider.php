<?php

declare(strict_types=1);

namespace EruoFood\Ai\Infrastructure\Provider;

use EruoFood\Ai\Application\DTO\AiCompletionRequest;
use EruoFood\Ai\Application\DTO\AiCompletionResult;
use EruoFood\Ai\Application\Port\AiProvider;
use EruoFood\Ai\Domain\Enum\AiProviderName;
use EruoFood\Ai\Domain\Enum\MessageRole;
use EruoFood\Ai\Domain\Exception\AiGenerationFailed;
use EruoFood\Ai\Domain\ValueObject\TokenUsage;
use Illuminate\Http\Client\Factory as HttpClient;
use Throwable;

/**
 * Google Gemini adapter over the Generative Language API.
 *
 * Gemini's shape differs from the others: the system prompt is a
 * `system_instruction`, turns live under `contents`, and the assistant role is
 * named `model`. This adapter maps the engine's neutral request onto that shape.
 */
final readonly class GeminiProvider implements AiProvider
{
    /**
     * @param array{api_key?: ?string, base_url?: string, model?: string} $config
     */
    public function __construct(
        private HttpClient $http,
        private array $config,
        private int $timeout,
    ) {
    }

    public function name(): AiProviderName
    {
        return AiProviderName::Gemini;
    }

    public function isConfigured(): bool
    {
        return ! empty($this->config['api_key']);
    }

    public function complete(AiCompletionRequest $request): AiCompletionResult
    {
        $model = $request->model ?? ($this->config['model'] ?? 'gemini-1.5-pro');

        $contents = [];
        foreach ($request->messages as $message) {
            $contents[] = [
                'role' => $message->role === MessageRole::Assistant ? 'model' : 'user',
                'parts' => [['text' => $message->content]],
            ];
        }

        try {
            $response = $this->http
                ->baseUrl((string) ($this->config['base_url'] ?? 'https://generativelanguage.googleapis.com/v1beta'))
                ->withHeaders(['x-goog-api-key' => (string) ($this->config['api_key'] ?? '')])
                ->timeout($this->timeout)
                ->post('/models/'.$model.':generateContent', [
                    'system_instruction' => ['parts' => [['text' => $request->system]]],
                    'contents' => $contents,
                    'generationConfig' => [
                        'maxOutputTokens' => $request->maxTokens,
                        'temperature' => $request->temperature,
                    ],
                ]);
        } catch (Throwable $e) {
            throw AiGenerationFailed::because('Gemini request failed: '.$e->getMessage(), $e);
        }

        if ($response->failed()) {
            throw AiGenerationFailed::because('Gemini returned HTTP '.$response->status());
        }

        $text = (string) $response->json('candidates.0.content.parts.0.text', '');
        if ($text === '') {
            throw AiGenerationFailed::because('Gemini returned an empty completion');
        }

        return new AiCompletionResult(
            text: $text,
            tokens: new TokenUsage(
                (int) $response->json('usageMetadata.promptTokenCount', 0),
                (int) $response->json('usageMetadata.candidatesTokenCount', 0),
            ),
            provider: AiProviderName::Gemini,
            model: $model,
            finishReason: (string) $response->json('candidates.0.finishReason', 'STOP'),
        );
    }
}
