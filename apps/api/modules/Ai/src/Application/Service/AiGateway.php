<?php

declare(strict_types=1);

namespace EruoFood\Ai\Application\Service;

use EruoFood\Ai\Application\DTO\AiCompletionRequest;
use EruoFood\Ai\Application\DTO\AiCompletionResult;
use EruoFood\Ai\Application\DTO\GatewaySettings;
use EruoFood\Ai\Application\Port\AiProvider;
use EruoFood\Ai\Application\Port\AiRateLimiter;
use EruoFood\Ai\Application\Port\AiResponseCache;
use EruoFood\Ai\Application\Port\CostCalculator;
use EruoFood\Ai\Application\Port\ProviderRegistry;
use EruoFood\Ai\Domain\Enum\AiFeature;
use EruoFood\Ai\Domain\Event\AiRequestCompleted;
use EruoFood\Ai\Domain\Exception\AiGenerationFailed;
use EruoFood\Ai\Domain\Exception\ProviderUnavailable;
use EruoFood\Ai\Domain\Usage\AiUsageLog;
use EruoFood\Ai\Domain\Usage\AiUsageLogRepository;
use EruoFood\Shared\Domain\Clock;
use EruoFood\Shared\Domain\EventBus;
use Throwable;

/**
 * The heart of the AI Service Layer — every feature ultimately calls here.
 *
 * A single {@see generate()} entry point runs the full cross-cutting pipeline so
 * feature services stay thin and consistent:
 *
 *   rate-limit → cache lookup → provider call (retry + fallback) → cost +
 *   usage logging → cache store → domain event
 *
 * Retry handles transient failures within one provider; the fallback chain
 * handles a provider being down entirely. Cache hits and failures are logged to
 * the usage ledger just like live calls so the AI Cost Tracking picture is
 * complete.
 */
final readonly class AiGateway
{
    public function __construct(
        private ProviderRegistry $providers,
        private AiResponseCache $cache,
        private AiRateLimiter $rateLimiter,
        private CostCalculator $costs,
        private AiUsageLogRepository $usage,
        private EventBus $events,
        private Clock $clock,
        private GatewaySettings $settings,
    ) {
    }

    /**
     * Run a completion through the full pipeline.
     *
     * @param bool $cacheable one-shot features cache; chat turns do not
     *
     * @throws \EruoFood\Ai\Domain\Exception\RateLimitExceeded
     * @throws \EruoFood\Ai\Domain\Exception\ProviderUnavailable
     * @throws \EruoFood\Ai\Domain\Exception\AiGenerationFailed
     */
    public function generate(
        AiFeature $feature,
        AiCompletionRequest $request,
        ?string $userId,
        bool $cacheable,
    ): AiCompletionResult {
        if ($userId !== null) {
            $this->rateLimiter->hit($userId);
        }

        $cacheKey = $feature->value.':'.$request->fingerprint();

        if ($cacheable && $this->settings->cacheEnabled) {
            $hit = $this->cache->get($cacheKey);
            if ($hit !== null) {
                $cached = $hit->servedFromCache();
                $this->log($feature, $userId, $cached, 0, true, null);

                return $cached;
            }
        }

        $result = $this->callWithFallback($feature, $request, $userId);

        if ($cacheable && $this->settings->cacheEnabled) {
            $this->cache->put($cacheKey, $result, $this->settings->cacheTtlSeconds);
        }

        $this->events->publish(new AiRequestCompleted(
            $feature,
            $result->provider->value,
            $result->model,
            $result->tokens->total(),
            false,
        ));

        return $result;
    }

    /**
     * Attempt each provider in the resolution chain, with in-provider retry and
     * exponential backoff. The first success wins; if all fail the last error is
     * recorded and surfaced.
     */
    private function callWithFallback(
        AiFeature $feature,
        AiCompletionRequest $request,
        ?string $userId,
    ): AiCompletionResult {
        $chain = $this->providers->resolutionChain();
        if ($chain === []) {
            throw ProviderUnavailable::allExhausted();
        }

        $lastError = null;

        foreach ($chain as $provider) {
            $startedAt = microtime(true);
            try {
                $result = $this->attemptWithRetry($provider, $request);
                $cost = $this->costs->costFor($result->provider, $result->model, $result->tokens);
                $this->log($feature, $userId, $result, $this->latencySince($startedAt), false, null, $cost);

                return $result;
            } catch (Throwable $e) {
                $lastError = $e;
            }
        }

        // Every provider failed — record the failure against the ledger and surface it.
        $this->logFailure($feature, $userId, $lastError);

        throw $lastError instanceof AiGenerationFailed
            ? $lastError
            : AiGenerationFailed::because('all providers failed', $lastError);
    }

    private function attemptWithRetry(AiProvider $provider, AiCompletionRequest $request): AiCompletionResult
    {
        $attempts = max(1, $this->settings->retryAttempts);
        $lastError = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                return $provider->complete($request);
            } catch (Throwable $e) {
                $lastError = $e;
                if ($attempt < $attempts) {
                    $this->backoff($attempt);
                }
            }
        }

        throw $lastError;
    }

    private function backoff(int $attempt): void
    {
        $delayMs = $this->settings->retryBaseDelayMs * (2 ** ($attempt - 1));
        if ($delayMs > 0) {
            usleep($delayMs * 1000);
        }
    }

    private function latencySince(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    private function log(
        AiFeature $feature,
        ?string $userId,
        AiCompletionResult $result,
        int $latencyMs,
        bool $cached,
        ?string $errorCode,
        float $cost = 0.0,
    ): void {
        $this->usage->record(new AiUsageLog(
            $this->usage->nextIdentity(),
            $userId,
            $feature,
            $result->provider,
            $result->model,
            $result->tokens,
            $cached ? 0.0 : $cost,
            $cached,
            $latencyMs,
            true,
            $errorCode,
            $this->clock->now(),
        ));
    }

    private function logFailure(AiFeature $feature, ?string $userId, ?Throwable $error): void
    {
        $code = $error instanceof AiGenerationFailed ? $error->errorCode() : 'AI_GENERATION_FAILED';

        $this->usage->record(new AiUsageLog(
            $this->usage->nextIdentity(),
            $userId,
            $feature,
            $this->providers->default()->name(),
            'n/a',
            \EruoFood\Ai\Domain\ValueObject\TokenUsage::zero(),
            0.0,
            false,
            0,
            false,
            $code,
            $this->clock->now(),
        ));
    }
}
