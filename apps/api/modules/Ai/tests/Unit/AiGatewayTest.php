<?php

declare(strict_types=1);

use EruoFood\Ai\Application\DTO\AiCompletionRequest;
use EruoFood\Ai\Application\DTO\GatewaySettings;
use EruoFood\Ai\Application\Service\AiGateway;
use EruoFood\Ai\Domain\Enum\AiFeature;
use EruoFood\Ai\Domain\Enum\AiProviderName;
use EruoFood\Ai\Domain\Event\AiRequestCompleted;
use EruoFood\Ai\Domain\Exception\ProviderUnavailable;
use EruoFood\Ai\Domain\Exception\RateLimitExceeded;
use EruoFood\Ai\Domain\ValueObject\AiMessage;
use EruoFood\Ai\Tests\Support\AllowAllRateLimiter;
use EruoFood\Ai\Tests\Support\ArrayResponseCache;
use EruoFood\Ai\Tests\Support\BlockingRateLimiter;
use EruoFood\Ai\Tests\Support\FakeClock;
use EruoFood\Ai\Tests\Support\FixedCostCalculator;
use EruoFood\Ai\Tests\Support\InMemoryUsageLog;
use EruoFood\Ai\Tests\Support\ListProviderRegistry;
use EruoFood\Ai\Tests\Support\RecordingEventBus;
use EruoFood\Ai\Tests\Support\ScriptedProvider;

/**
 * @param list<\EruoFood\Ai\Application\Port\AiProvider> $providers
 */
function buildGateway(
    array $providers,
    ?ArrayResponseCache $cache = null,
    ?object $limiter = null,
    ?InMemoryUsageLog $usage = null,
    ?RecordingEventBus $events = null,
    bool $cacheEnabled = true,
): AiGateway {
    return new AiGateway(
        new ListProviderRegistry($providers),
        $cache ?? new ArrayResponseCache(),
        $limiter ?? new AllowAllRateLimiter(),
        new FixedCostCalculator(0.02),
        $usage ?? new InMemoryUsageLog(),
        $events ?? new RecordingEventBus(),
        new FakeClock(),
        new GatewaySettings(cacheEnabled: $cacheEnabled, cacheTtlSeconds: 60, retryAttempts: 2, retryBaseDelayMs: 0),
    );
}

function gwRequest(): AiCompletionRequest
{
    return new AiCompletionRequest('system', [AiMessage::user('make jollof')], 512, 0.5);
}

it('records usage and publishes an event on a successful generation', function (): void {
    $usage = new InMemoryUsageLog();
    $events = new RecordingEventBus();
    $gateway = buildGateway([ScriptedProvider::alwaysReturns('the answer')], usage: $usage, events: $events);

    $result = $gateway->generate(AiFeature::RecipeGeneration, gwRequest(), 'user-1', true);

    expect($result->text)->toBe('the answer')
        ->and($result->cached)->toBeFalse()
        ->and($usage->logs)->toHaveCount(1)
        ->and($usage->last()->wasSuccessful())->toBeTrue()
        ->and($usage->last()->costUsd())->toBe(0.02)
        ->and($events->published)->toHaveCount(1)
        ->and($events->published[0])->toBeInstanceOf(AiRequestCompleted::class);
});

it('serves a cached response without calling the provider', function (): void {
    $cache = new ArrayResponseCache();
    $provider = ScriptedProvider::alwaysReturns('fresh');
    $usage = new InMemoryUsageLog();

    // Pre-seed the cache under the gateway's key scheme.
    $request = gwRequest();
    $cache->store[AiFeature::RecipeGeneration->value.':'.$request->fingerprint()]
        = ScriptedProvider::result('cached answer');

    $result = buildGateway([$provider], cache: $cache, usage: $usage)
        ->generate(AiFeature::RecipeGeneration, $request, 'user-1', true);

    expect($result->text)->toBe('cached answer')
        ->and($result->cached)->toBeTrue()
        ->and($provider->calls)->toBe(0)
        ->and($usage->last()->wasCached())->toBeTrue();
});

it('does not read the cache for non-cacheable (chat) features', function (): void {
    $cache = new ArrayResponseCache();
    $request = gwRequest();
    $cache->store[AiFeature::CookingAssistant->value.':'.$request->fingerprint()]
        = ScriptedProvider::result('stale cached');

    $result = buildGateway([ScriptedProvider::alwaysReturns('live reply')], cache: $cache)
        ->generate(AiFeature::CookingAssistant, $request, 'user-1', false);

    expect($result->text)->toBe('live reply')->and($result->cached)->toBeFalse();
});

it('falls back to the next provider when the first is down', function (): void {
    $bad = ScriptedProvider::alwaysFails(AiProviderName::OpenAi);
    $good = ScriptedProvider::alwaysReturns('rescued', AiProviderName::Anthropic);

    $result = buildGateway([$bad, $good])->generate(AiFeature::MealSuggestions, gwRequest(), 'u', true);

    expect($result->text)->toBe('rescued')
        ->and($result->provider)->toBe(AiProviderName::Anthropic)
        ->and($bad->calls)->toBeGreaterThanOrEqual(1)
        ->and($good->calls)->toBe(1);
});

it('retries a flaky provider before succeeding', function (): void {
    // Fail once, then succeed — within the same provider's retry budget (2).
    $flaky = new ScriptedProvider(AiProviderName::Mock, [
        \EruoFood\Ai\Domain\Exception\AiGenerationFailed::because('transient'),
        ScriptedProvider::result('second try'),
    ]);

    $result = buildGateway([$flaky])->generate(AiFeature::CookingTips, gwRequest(), 'u', true);

    expect($result->text)->toBe('second try')->and($flaky->calls)->toBe(2);
});

it('rejects the call and never reaches a provider when rate limited', function (): void {
    $provider = ScriptedProvider::alwaysReturns('never');
    $usage = new InMemoryUsageLog();

    expect(fn () => buildGateway([$provider], limiter: new BlockingRateLimiter(), usage: $usage)
        ->generate(AiFeature::RecipeGeneration, gwRequest(), 'user-1', true))
        ->toThrow(RateLimitExceeded::class);

    expect($provider->calls)->toBe(0);
});

it('records a failure and throws when every provider is exhausted', function (): void {
    $usage = new InMemoryUsageLog();
    $gateway = buildGateway([ScriptedProvider::alwaysFails()], usage: $usage);

    expect(fn () => $gateway->generate(AiFeature::RecipeGeneration, gwRequest(), 'u', true))
        ->toThrow(\EruoFood\Ai\Domain\Exception\AiGenerationFailed::class);

    expect($usage->last()->wasSuccessful())->toBeFalse();
});

it('throws when the provider chain is empty', function (): void {
    expect(fn () => buildGateway([])->generate(AiFeature::RecipeGeneration, gwRequest(), 'u', true))
        ->toThrow(ProviderUnavailable::class);
});
