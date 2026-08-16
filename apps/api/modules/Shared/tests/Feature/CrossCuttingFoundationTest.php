<?php

declare(strict_types=1);

use EruoFood\Shared\Domain\Correlation\CorrelationContext;
use EruoFood\Shared\Domain\Correlation\CorrelationId;
use EruoFood\Shared\Domain\Exception\InvalidArgumentException;
use EruoFood\Shared\Domain\Flag\FeatureFlag;
use EruoFood\Shared\Domain\Flag\FlagEvaluator;
use EruoFood\Shared\Domain\Flag\FlagReason;
use EruoFood\Shared\Domain\Flag\FlagRegistry;
use EruoFood\Shared\Domain\Flag\FlagTarget;
use EruoFood\Shared\Domain\Schedule\Cadence;
use EruoFood\Shared\Domain\Schedule\ScheduledTask;
use EruoFood\Shared\Domain\Schedule\ScheduleRegistry;

// ============================================================ timezone storage

it('keeps UTC as the authoritative application timezone', function (): void {
    // The locked rule. This was 'Africa/Lagos', which meant every timestamp in
    // 167 timezone-naive columns held local wall-clock while PostgreSQL itself
    // ran in UTC. If this fails, the database has started meaning something
    // different from what it means today.
    expect(config('app.timezone'))->toBe('UTC');
});

// ============================================================== feature flags

it('defaults every high-risk flag to off', function (): void {
    $evaluator = app(FlagEvaluator::class);

    foreach (app(FlagRegistry::class)->all() as $flag) {
        expect($evaluator->isEnabled($flag->key))
            ->toBeFalse("Flag '{$flag->key}' must default to off.");
    }
});

it('leaves the M26 dispatch engine switched off', function (): void {
    // The standing instruction across M26 and this change. Asserted here as
    // well as in config so that turning it on requires an obvious, deliberate
    // edit that fails a test first.
    expect(config('dispatch.engine.enabled'))->toBeFalse()
        ->and(app(FlagEvaluator::class)->isEnabled('dispatch.engine'))->toBeFalse();
});

it('refuses to evaluate a flag nobody registered', function (): void {
    // A typo returning a quiet `false` is indistinguishable from a correctly
    // disabled feature, and can hide for months.
    expect(fn () => app(FlagEvaluator::class)->isEnabled('dispatch.egnine'))
        ->toThrow(InvalidArgumentException::class, 'Unknown feature flag');
});

it('lets the environment override beat every rollout rule', function (): void {
    config()->set('flags.overrides.dispatch.stale_rider_sweep', true);
    config()->set('flags.rollout.dispatch.stale_rider_sweep', ['percentage' => 0]);

    $decision = app(FlagEvaluator::class)->explain(
        'dispatch.stale_rider_sweep',
        FlagTarget::of(userId: 'user-1'),
    );

    expect($decision->enabled)->toBeTrue()
        ->and($decision->reason)->toBe(FlagReason::EnvironmentOverride);
});

it('lets the kill switch win even against an explicit target match', function (): void {
    // The property that makes it a kill switch: nothing below it can re-enable
    // the capability during an incident.
    config()->set('flags.overrides.dispatch.stale_rider_sweep', false);
    config()->set('flags.rollout.dispatch.stale_rider_sweep', ['merchants' => ['m-1']]);

    expect(app(FlagEvaluator::class)->isEnabled(
        'dispatch.stale_rider_sweep',
        FlagTarget::of(merchantId: 'm-1'),
    ))->toBeFalse();
});

it('enables a flag for an explicitly targeted merchant only', function (): void {
    config()->set('flags.rollout.dispatch.stale_rider_sweep', ['merchants' => ['m-1']]);

    $evaluator = app(FlagEvaluator::class);

    expect($evaluator->isEnabled('dispatch.stale_rider_sweep', FlagTarget::of(merchantId: 'm-1')))->toBeTrue()
        ->and($evaluator->isEnabled('dispatch.stale_rider_sweep', FlagTarget::of(merchantId: 'm-2')))->toBeFalse();
});

it('never matches a dimension the caller did not supply', function (): void {
    // Missing context must be able to fail to enable something, and must never
    // accidentally enable it.
    config()->set('flags.rollout.dispatch.stale_rider_sweep', ['countries' => ['NG']]);

    expect(app(FlagEvaluator::class)->isEnabled('dispatch.stale_rider_sweep', FlagTarget::none()))
        ->toBeFalse();
});

it('does not let a null entry in a rollout list match a caller with no context', function (): void {
    // The case the null-guard actually exists for, and the one the test above
    // does not reach: a misconfigured rollout list containing an empty entry.
    // Without the guard, `in_array(null, [null], true)` is true and the flag
    // turns on for every caller that supplied no country — a config typo
    // becoming a platform-wide enable.
    //
    // The false-positive audit caught this: removing the guard left the test
    // above green, because a strict comparison against ['NG'] never matches
    // null anyway. That test proved the behaviour, not the protection.
    config()->set('flags.rollout.dispatch.stale_rider_sweep', ['countries' => [null], 'users' => [null]]);

    expect(app(FlagEvaluator::class)->isEnabled('dispatch.stale_rider_sweep', FlagTarget::none()))
        ->toBeFalse();
});

it('keeps a percentage bucket stable for the same subject and flag', function (): void {
    config()->set('flags.rollout.dispatch.stale_rider_sweep', ['percentage' => 50]);

    $evaluator = app(FlagEvaluator::class);
    $target = FlagTarget::of(userId: 'stable-user');

    $first = $evaluator->isEnabled('dispatch.stale_rider_sweep', $target);

    for ($i = 0; $i < 20; $i++) {
        expect($evaluator->isEnabled('dispatch.stale_rider_sweep', $target))->toBe($first);
    }
});

it('puts the same subject in different buckets for different flags', function (): void {
    // Otherwise one unlucky cohort receives every experimental feature at once.
    $target = FlagTarget::of(userId: 'user-42');

    expect($target->bucketFor('dispatch.stale_rider_sweep'))
        ->not->toBe($target->bucketFor('notifications.retry'));
});

it('refuses a percentage rollout when there is no subject to bucket', function (): void {
    // A background sweep has no user. Treating that as "inside the rollout"
    // would enable the feature for every background caller at once.
    config()->set('flags.rollout.notifications.retry', ['percentage' => 99]);

    $decision = app(FlagEvaluator::class)->explain('notifications.retry', FlagTarget::none());

    expect($decision->enabled)->toBeFalse()
        ->and($decision->reason)->toBe(FlagReason::SafeDefault);
});

it('requires an owner and a rollback strategy for every flag', function (): void {
    // A flag with no documented rollback is one somebody enables on a Friday
    // having assumed there is one.
    expect(fn () => FeatureFlag::of('x.y', false, 'desc', '', 'rollout', 'rollback'))
        ->toThrow(InvalidArgumentException::class, 'owner')
        ->and(fn () => FeatureFlag::of('x.y', false, 'desc', 'owner', 'rollout', ''))
        ->toThrow(InvalidArgumentException::class, 'rollback strategy');
});

it('derives a flag environment variable from its key', function (): void {
    expect(app(FlagRegistry::class)->get('dispatch.engine')->environmentVariable())
        ->toBe('FLAG_DISPATCH_ENGINE');
});

// ============================================================= correlation ids

it('stamps a correlation id on every API response', function (): void {
    $this->getJson('/api/v1/health')
        ->assertOk()
        ->assertHeader('X-Request-Id');
});

it('echoes a caller-supplied request id back', function (): void {
    $this->withHeader('X-Request-Id', 'client-trace-1')
        ->getJson('/api/v1/health')
        ->assertHeader('X-Request-Id', 'client-trace-1');
});

it('discards a caller id that could forge a log line', function (string $hostile): void {
    $response = $this->withHeader('X-Request-Id', $hostile)->getJson('/api/v1/health');

    // The request still succeeds — refusing it would turn a diagnostic header
    // into an outage — but the value never reaches a log line.
    $response->assertOk();

    expect($response->headers->get('X-Request-Id'))->not->toBe($hostile);
})->with([
    'newline injection' => ["abc\ninjected"],
    'whitespace' => ['abc injected'],
    'unbounded length' => [str_repeat('a', 200)],
]);

it('keeps the audit id server-generated even when the caller supplies one', function (): void {
    $correlation = CorrelationId::fromInbound('caller-chosen');

    expect($correlation->forResponse())->toBe('caller-chosen')
        ->and($correlation->forAudit())->not->toBe('caller-chosen');
});

it('generates a correlation for work that did not arrive over HTTP', function (): void {
    CorrelationContext::clear();

    expect(CorrelationContext::has())->toBeFalse()
        ->and(CorrelationContext::current()->forAudit())->toBeString()
        ->and(CorrelationContext::has())->toBeTrue();
});

it('releases the correlation so the next job does not inherit it', function (): void {
    // A worker that does not clear this stamps job #2 with job #1's id, which
    // is worse than no id: it asserts a relationship that never existed.
    CorrelationContext::set(CorrelationId::generate());
    $first = CorrelationContext::current()->forAudit();

    CorrelationContext::clear();

    expect(CorrelationContext::current()->forAudit())->not->toBe($first);
});

// ================================================================= scheduling

it('schedules nothing that would start automatic dispatch', function (): void {
    // Wiring DispatchEngine into a scheduler is what "switching automatic
    // dispatch on" means. Nothing in this foundation may do that.
    //
    // Written as a filter over the whole registry rather than a loop, so it
    // asserts something even while the registry is empty — a loop over no rows
    // passes without testing anything, which is how this kind of guard quietly
    // stops guarding.
    $dispatchTasks = array_filter(
        app(ScheduleRegistry::class)->enabled(),
        static fn ($task): bool => str_contains($task->command, 'dispatch:'),
    );

    expect($dispatchTasks)->toBe([]);
});

it('reports disabled tasks as registered but not scheduled', function (): void {
    $registry = new ScheduleRegistry();

    $registry->register(ScheduledTask::of(
        name: 'demo:off',
        command: 'demo:off',
        cadence: Cadence::Hourly,
        enabled: false,
        description: 'A task that exists but is switched off.',
    ));

    // A task missing from the list because it is disabled looks identical to
    // one nobody ever wrote.
    expect($registry->all())->toHaveCount(1)
        ->and($registry->enabled())->toHaveCount(0);
});

it('refuses two scheduled tasks with the same name', function (): void {
    // They would share an overlap lock and the second would silently never run.
    $registry = new ScheduleRegistry();
    $task = ScheduledTask::of('demo:dup', 'demo:dup', Cadence::Daily, true, 'First.');

    $registry->register($task);

    expect(fn () => $registry->register($task))
        ->toThrow(InvalidArgumentException::class, 'already registered');
});

it('requires a description an operator can act on at 3am', function (): void {
    expect(fn () => ScheduledTask::of('demo:x', 'demo:x', Cadence::Daily, true, '  '))
        ->toThrow(InvalidArgumentException::class, 'description');
});
