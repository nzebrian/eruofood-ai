<?php

declare(strict_types=1);

namespace EruoFood\Ai\Application\DTO;

use EruoFood\Ai\Domain\ValueObject\AiMessage;

/**
 * A provider-agnostic completion request.
 *
 * This is the single shape every {@see \EruoFood\Ai\Application\Port\AiProvider}
 * adapter consumes; each adapter translates it into its own wire format. Keeping
 * one request DTO means the gateway can retry the identical call against a
 * different provider without rebuilding anything.
 */
final readonly class AiCompletionRequest
{
    /**
     * @param list<AiMessage> $messages the user/assistant turns (system is separate)
     * @param string|null $model optional provider model id override (else provider default)
     */
    public function __construct(
        public string $system,
        public array $messages,
        public int $maxTokens,
        public float $temperature,
        public ?string $model = null,
    ) {
    }

    /**
     * Deterministic fingerprint over everything that affects the output — used
     * to key the response cache so identical requests are served without a
     * second provider call.
     */
    public function fingerprint(): string
    {
        $payload = [
            'system' => $this->system,
            'messages' => array_map(static fn (AiMessage $m): array => $m->toArray(), $this->messages),
            'max_tokens' => $this->maxTokens,
            'temperature' => $this->temperature,
            'model' => $this->model,
        ];

        return hash('sha256', (string) json_encode($payload));
    }
}
