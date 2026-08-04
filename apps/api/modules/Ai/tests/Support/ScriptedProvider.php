<?php

declare(strict_types=1);

namespace EruoFood\Ai\Tests\Support;

use EruoFood\Ai\Application\DTO\AiCompletionRequest;
use EruoFood\Ai\Application\DTO\AiCompletionResult;
use EruoFood\Ai\Application\Port\AiProvider;
use EruoFood\Ai\Domain\Enum\AiProviderName;
use EruoFood\Ai\Domain\Exception\AiGenerationFailed;
use EruoFood\Ai\Domain\ValueObject\TokenUsage;
use Throwable;

/**
 * A provider whose behaviour is scripted: each call pops the next outcome from a
 * queue (either a result or a throwable). Lets gateway tests drive retry and
 * fallback paths deterministically.
 */
final class ScriptedProvider implements AiProvider
{
    public int $calls = 0;

    /** @var list<AiCompletionResult|Throwable> */
    private array $outcomes;

    /** @param list<AiCompletionResult|Throwable> $outcomes */
    public function __construct(
        private readonly AiProviderName $name,
        array $outcomes,
    ) {
        $this->outcomes = $outcomes;
    }

    /** Convenience: a provider that always returns the given text. */
    public static function alwaysReturns(string $text, AiProviderName $name = AiProviderName::Mock): self
    {
        return new self($name, [self::result($text, $name)]);
    }

    /** Convenience: a provider that always throws. */
    public static function alwaysFails(AiProviderName $name = AiProviderName::OpenAi): self
    {
        return new self($name, [AiGenerationFailed::because('scripted failure')]);
    }

    public static function result(string $text, AiProviderName $name = AiProviderName::Mock): AiCompletionResult
    {
        return new AiCompletionResult($text, new TokenUsage(10, 5), $name, 'test-model', 'stop');
    }

    public function name(): AiProviderName
    {
        return $this->name;
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function complete(AiCompletionRequest $request): AiCompletionResult
    {
        $this->calls++;
        // Reuse the last outcome once the queue is drained (so "always" stubs loop).
        $outcome = count($this->outcomes) > 1 ? array_shift($this->outcomes) : ($this->outcomes[0] ?? null);

        if ($outcome instanceof Throwable) {
            throw $outcome;
        }
        if ($outcome instanceof AiCompletionResult) {
            return $outcome;
        }

        throw AiGenerationFailed::because('no scripted outcome');
    }
}
