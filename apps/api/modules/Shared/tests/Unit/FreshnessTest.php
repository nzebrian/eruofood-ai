<?php

declare(strict_types=1);

use EruoFood\Shared\Domain\Connectivity\FreshnessPolicy;
use EruoFood\Shared\Domain\Connectivity\FreshnessState;
use EruoFood\Shared\Domain\Connectivity\Observation;
use EruoFood\Shared\Domain\Exception\InvalidArgumentException;

$policy = FreshnessPolicy::of(liveWithinSeconds: 60, usableWithinSeconds: 300);
$now = new DateTimeImmutable('2026-08-15T12:00:00Z');
$ago = static fn (int $seconds): DateTimeImmutable => new DateTimeImmutable('2026-08-15T12:00:00Z')
    ->modify("-{$seconds} seconds");

// ------------------------------------------------------- the safety property

it('never presents anything but Online as live', function (): void {
    // The contract the whole envelope exists to enforce: only one state may be
    // shown without a caveat.
    foreach (FreshnessState::cases() as $state) {
        expect($state->mayBePresentedAsLive())->toBe($state === FreshnessState::Online);
    }
});

it('only lets a decision rest on Online data', function (): void {
    // Showing an old position is a discourtesy; dispatching against one sends a
    // rider from where they used to be.
    foreach (FreshnessState::cases() as $state) {
        expect($state->isSafeToActOn())->toBe($state === FreshnessState::Online);
    }
});

it('treats undated data as stale rather than current', function (): void {
    // The default that stops stale data being rendered as live.
    $observation = Observation::undated(['lat' => 6.5, 'lng' => 3.3]);

    expect($observation->freshness)->toBe(FreshnessState::StaleUnknown)
        ->and($observation->isSafeToActOn())->toBeFalse()
        ->and($observation->hasValue())->toBeTrue();
});

it('refuses to call a future timestamp fresh', function () use ($policy): void {
    // A clock disagreement is the one thing a future observation is evidence
    // of, and it is not evidence of freshness.
    expect($policy->judge(-30))->toBe(FreshnessState::StaleUnknown);
});

// --------------------------------------------------------------- the bands

it('grades an observation by age', function (int $age, FreshnessState $expected) use ($policy): void {
    expect($policy->judge($age))->toBe($expected);
})->with([
    'fresh' => [0, FreshnessState::Online],
    'at the live boundary' => [60, FreshnessState::Online],
    'just past it' => [61, FreshnessState::Degraded],
    'at the usable boundary' => [300, FreshnessState::Degraded],
    'beyond usable' => [301, FreshnessState::StaleUnknown],
]);

it('keeps a middle band so old data is neither claimed nor discarded', function () use ($policy, $ago, $now): void {
    // A four-minute-old rider position still tells a customer roughly where
    // their food is. A single cutoff would force a choice between claiming
    // freshness we do not have and throwing it away.
    $observation = Observation::of(['lat' => 6.5], $ago(240), $now, $policy);

    expect($observation->freshness)->toBe(FreshnessState::Degraded)
        ->and($observation->freshness->isUsableWithCaveat())->toBeTrue()
        ->and($observation->freshness->mayBePresentedAsLive())->toBeFalse()
        ->and($observation->ageSeconds)->toBe(240);
});

it('aligns the rider policy with the dispatch staleness rule', function (): void {
    // M26's LocationIsFresh uses geo.privacy.rider_location_stale_seconds. A
    // rider too stale to dispatch to must also be too stale to draw on a
    // customer's map as live, or the two components disagree about "fresh".
    $riderPolicy = FreshnessPolicy::riderPosition(300);

    expect($riderPolicy->judge(299))->toBe(FreshnessState::Online)
        ->and($riderPolicy->judge(301))->toBe(FreshnessState::Degraded);
});

it('refuses a policy whose degraded band is inverted', function (): void {
    expect(fn () => FreshnessPolicy::of(300, 60))
        ->toThrow(InvalidArgumentException::class, 'at least as long');
});

it('refuses a non-positive live window', function (): void {
    expect(fn () => FreshnessPolicy::of(0, 60))->toThrow(InvalidArgumentException::class, 'positive');
});

// ------------------------------------------------------------- the envelope

it('reports absence with a reason rather than a bare null', function (): void {
    // "The phone is off", "we have not looked" and "they are at the
    // restaurant" are three different facts. Null for all three loses what
    // operations needs.
    $observation = Observation::unavailable(FreshnessState::Offline, 'Rider heartbeat lost.');

    expect($observation->hasValue())->toBeFalse()
        ->and($observation->freshness)->toBe(FreshnessState::Offline)
        ->and($observation->note)->toBe('Rider heartbeat lost.')
        ->and($observation->isSafeToActOn())->toBeFalse();
});

it('always carries freshness on the wire, even with no value', function (): void {
    $wire = Observation::unavailable(FreshnessState::Offline)->toArray();

    expect($wire)->toHaveKeys(['value', 'observed_at', 'freshness', 'age_seconds', 'note'])
        ->and($wire['value'])->toBeNull()
        ->and($wire['freshness'])->toBe('offline');
});

it('distinguishes a known-offline source from an unknown one', function (): void {
    // "The rider's phone is off" and "we have not checked" are different things
    // to put in front of an operator.
    expect(FreshnessState::Offline)->not->toBe(FreshnessState::StaleUnknown);
});
