<?php

declare(strict_types=1);

use EruoFood\Shared\Domain\DataLifecycle\DeletionMode;
use EruoFood\Shared\Domain\DataLifecycle\RetentionEnforcement;
use EruoFood\Shared\Domain\DataLifecycle\RetentionGate;
use EruoFood\Shared\Domain\DataLifecycle\RetentionRegistry;
use EruoFood\Shared\Domain\Idempotency\IdempotencyStore;
use EruoFood\Shared\Domain\Schedule\ScheduledTask;
use EruoFood\Shared\Domain\Schedule\ScheduleRegistry;
use EruoFood\Shared\Infrastructure\Idempotency\Model\IdempotencyKeyModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * M42 — retention is enforced, and enforcement is off.
 *
 * Two claims that sound contradictory and are not. The commands exist and work;
 * nothing runs them unattended. `DeletionMode::isReversible()` is true for
 * exactly one mode, and the modes here are not it, so "it works" and "it is not
 * running" both have to be proved.
 */

/** Insert a claim with an explicit expiry. Synthetic values only. */
function claim(string $scope, string $key, DateTimeInterface|string $expiresAt, DateTimeInterface|string|null $createdAt = null): string
{
    $id = (string) Str::orderedUuid();

    IdempotencyKeyModel::query()->create([
        'id' => $id,
        'scope' => $scope,
        'idempotency_key' => $key,
        'request_hash' => str_repeat('a', 64),
        'user_id' => null,
        'state' => IdempotencyKeyModel::STATE_COMPLETED,
        'created_at' => $createdAt ?? now()->subDays(400),
        'expires_at' => $expiresAt,
    ]);

    return $id;
}

function storedKeys(): array
{
    return IdempotencyKeyModel::query()->orderBy('idempotency_key')->pluck('idempotency_key')->all();
}

/** Run a command and return [exitCode, output]. */
function runCmd(string $command, array $options = []): array
{
    $code = Artisan::call($command, $options);

    return [$code, Artisan::output()];
}

/**
 * A fixed Clock, bound for these tests.
 *
 * `SystemClock` calls `new DateTimeImmutable('now')`, which Laravel's
 * `travelTo()` does not affect — it moves Carbon, not PHP's constructor. The
 * store's expiry comparison therefore cannot be time-travelled, and pretending
 * otherwise is how a boundary test silently stops testing the boundary. Binding
 * the port is the honest alternative and needs no production change.
 */
beforeEach(function (): void {
    $this->frozen = new DateTimeImmutable('2027-06-01 12:00:00', new DateTimeZone('UTC'));

    $this->app->bind(EruoFood\Shared\Domain\Clock::class, fn (): EruoFood\Shared\Domain\Clock => new class ($this->frozen) implements EruoFood\Shared\Domain\Clock {
        public function __construct(private DateTimeImmutable $at)
        {
        }

        public function now(): DateTimeImmutable
        {
            return $this->at;
        }
    });

    // The store is a singleton built with the Clock it saw first.
    $this->app->forgetInstance(IdempotencyStore::class);

    $this->travelTo($this->frozen);
});

// =============================================================================
// The coverage ratchet
// =============================================================================

it('gives every non-indefinite retention policy an enforcement path or a written reason', function (): void {
    // Walks the REGISTRY, not a hard-coded list. A policy added later with a
    // finite window and no enforcement entry makes RetentionEnforcement::for()
    // throw, and this fails by name — which is the whole point of the ratchet.
    $registry = RetentionRegistry::platformDefaults();
    $checked = 0;

    foreach ($registry->all() as $policy) {
        if ($policy->isIndefinite()) {
            continue;
        }

        $checked++;
        $command = RetentionEnforcement::for($policy->key);

        if ($command === null) {
            expect(RetentionEnforcement::exemptionReason($policy->key))
                ->toBeString()
                ->not->toBeEmpty();

            continue;
        }

        // Pest's toContain treats extra arguments as further needles, not as a
        // failure message, so the context goes in the variable name instead.
        $registered = array_keys(Artisan::all());
        expect($registered)->toContain($command);
    }

    // Guards the guard: if the registry were empty, or every policy indefinite,
    // the loop above would assert nothing at all and still pass.
    expect($checked)->toBeGreaterThanOrEqual(5);
});

it('refuses a policy that is neither enforced nor documented as exempt', function (): void {
    // Proves the ratchet discriminates. Without this, the test above passes for
    // a lookup that simply never fails.
    expect(fn () => RetentionEnforcement::for('some.policy_added_next_year'))
        ->toThrow(EruoFood\Shared\Domain\Exception\InvalidArgumentException::class, 'neither an enforcement command');
});

it('does not silently convert an Anonymise policy into a Destroy one', function (): void {
    // notifications.sent is exempt precisely BECAUSE its mode is Anonymise and
    // no anonymisation mechanism exists. If somebody "fixes" the gap by flipping
    // the mode, the policy stops meaning what it said and this fails.
    $policy = RetentionRegistry::platformDefaults()->get('notifications.sent');

    expect($policy->deletionMode)->toBe(DeletionMode::Anonymise)
        ->and(RetentionEnforcement::for('notifications.sent'))->toBeNull()
        ->and(RetentionEnforcement::exemptionReason('notifications.sent'))->toContain('NOT NULL');
});

// =============================================================================
// Everything ships off
// =============================================================================

it('registers every retention task disabled, and none of them is scheduled', function (): void {
    $registry = app(ScheduleRegistry::class);

    $retention = array_values(array_filter(
        $registry->all(),
        static fn (ScheduledTask $t): bool => $t->destructiveRetention,
    ));

    expect($retention)->not->toBeEmpty();

    foreach ($retention as $task) {
        expect($task->enabled)->toBeFalse();
    }

    // And none reaches the scheduler at all.
    $enabled = array_map(static fn (ScheduledTask $t): string => $t->name, $registry->enabled());

    foreach ($retention as $task) {
        expect($enabled)->not->toContain($task->name);
    }
});

it('keeps the master flag off, so an enabled task still would not run unattended', function (): void {
    expect(app(RetentionGate::class)->allowsScheduledPurge())->toBeFalse();

    // The second lock is independent of the first. Even with a task enabled,
    // the gate closed means the bootstrap skips it — proved here by driving the
    // same predicate the bootstrap uses.
    $task = ScheduledTask::of(
        'control:enabled-retention',
        'control:enabled-retention',
        EruoFood\Shared\Domain\Schedule\Cadence::Daily,
        true,
        'Control fixture: enabled AND destructive. Never registered.',
        true,
        true,
    );

    $wouldSchedule = $task->enabled
        && (! $task->destructiveRetention || app(RetentionGate::class)->allowsScheduledPurge());

    expect($wouldSchedule)->toBeFalse();

    // And the same predicate lets a NON-retention enabled task through, so the
    // assertion above is about the gate rather than about `enabled`.
    $ordinary = ScheduledTask::of(
        'control:ordinary',
        'control:ordinary',
        EruoFood\Shared\Domain\Schedule\Cadence::Daily,
        true,
        'Control fixture: enabled, not retention.',
    );

    expect($ordinary->enabled && (! $ordinary->destructiveRetention || app(RetentionGate::class)->allowsScheduledPurge()))
        ->toBeTrue();
});

// =============================================================================
// shared:purge-idempotency-keys
// =============================================================================

it('deletes an expired claim and leaves an unexpired one alone', function (): void {
    claim('payments.initiate', 'EXPIRED', now()->subHour()->toDateTimeString());
    claim('payments.subscription', 'LIVE', now()->addHour()->toDateTimeString());

    [$code, $out] = runCmd('shared:purge-idempotency-keys');

    expect($code)->toBe(0)
        ->and(storedKeys())->toBe(['LIVE'])
        ->and($out)->toContain('Purged 1 of 1');
});

it('uses expires_at and not created_at, so an old but live claim survives', function (): void {
    // The whole safety property. This row is 400 days old and expires tomorrow:
    // a created_at window of any size would delete it, and deleting it would
    // reopen the duplicate-payment window it exists to close.
    claim('payments.refund', 'OLD BUT LIVE', now()->addDay()->toDateTimeString(), now()->subDays(400));

    [$code] = runCmd('shared:purge-idempotency-keys');

    expect($code)->toBe(0)
        ->and(storedKeys())->toBe(['OLD BUT LIVE']);
});

it('treats a claim expiring exactly now as not yet expired', function (): void {
    claim('commerce.checkout', 'ON THE BOUNDARY', now()->toDateTimeString());

    runCmd('shared:purge-idempotency-keys');

    // Strictly `<`. A row at the boundary is inside the window.
    expect(storedKeys())->toBe(['ON THE BOUNDARY']);
});

it('changes nothing on a dry run', function (): void {
    claim('payments.initiate', 'EXPIRED', now()->subHour()->toDateTimeString());

    [$code, $out] = runCmd('shared:purge-idempotency-keys', ['--dry-run' => true]);

    expect($code)->toBe(0)
        ->and($out)->toContain('Dry run')
        ->and($out)->toContain('Nothing was deleted')
        ->and(storedKeys())->toBe(['EXPIRED']);
});

it('refuses a non-positive chunk instead of purging', function (): void {
    claim('payments.initiate', 'EXPIRED', now()->subHour()->toDateTimeString());

    foreach ([0, -5] as $chunk) {
        [$code, $out] = runCmd('shared:purge-idempotency-keys', ['--chunk' => $chunk]);

        expect($code)->toBe(1)
            ->and($out)->toContain('positive');
    }

    expect(storedKeys())->toBe(['EXPIRED']);
});

it('purges a backlog larger than one chunk', function (): void {
    for ($i = 0; $i < 25; $i++) {
        claim('payments.initiate', sprintf('EXPIRED-%02d', $i), now()->subHour()->toDateTimeString());
    }
    claim('payments.initiate', 'LIVE', now()->addHour()->toDateTimeString());

    [$code, $out] = runCmd('shared:purge-idempotency-keys', ['--chunk' => 4]);

    // Chunking is about statement size, not about how much gets removed.
    expect($code)->toBe(0)
        ->and($out)->toContain('Purged 25 of 25')
        ->and(storedKeys())->toBe(['LIVE']);
});

it('prints no key, hash, snapshot or user id', function (): void {
    $store = app(IdempotencyStore::class);
    $store->execute(
        'payments.subscription',
        'SECRET-LOOKING-KEY',
        str_repeat('f', 64),
        fn (): array => ['id' => 'sub-1', 'plan' => 'gold'],
        '11111111-1111-4111-8111-111111111111',
    );

    IdempotencyKeyModel::query()->update(['expires_at' => now()->subHour()]);

    [, $out] = runCmd('shared:purge-idempotency-keys');

    expect($out)->not->toContain('SECRET-LOOKING-KEY')
        ->not->toContain('11111111-1111-4111-8111-111111111111')
        ->not->toContain('sub-1')
        ->not->toContain('gold')
        ->not->toContain(str_repeat('f', 64));
});
