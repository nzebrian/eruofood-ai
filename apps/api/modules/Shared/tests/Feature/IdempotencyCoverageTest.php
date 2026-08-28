<?php

declare(strict_types=1);

use EruoFood\Shared\Domain\Clock;
use EruoFood\Shared\Domain\Exception\IdempotencyConflict;
use EruoFood\Shared\Domain\Idempotency\IdempotencyStore;
use EruoFood\Shared\Infrastructure\Idempotency\EloquentIdempotencyStore;
use EruoFood\Shared\Infrastructure\Idempotency\Model\IdempotencyKeyModel;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Coverage audit and crash recovery for the M23 idempotency store.
 *
 * Two separate things are pinned here. The first is *which* money-moving
 * operations are guarded — a list that must not quietly shrink, and which the
 * audit found had a hole in it. The second is what happens when the process
 * holding a claim dies, which is the case a client cannot distinguish from
 * success.
 */

// ---------------------------------------------------------- coverage audit

it('guards every money-moving operation with an idempotency scope', function (): void {
    // The audit that found `payments.initiate` missing. Checkout, wallet
    // top-up, transfer, refund and dispatch acceptance were all guarded while
    // the endpoint that opens a charge at the provider was not — so a client
    // that timed out and retried opened a *second* payment intent for the same
    // order, and the customer could complete both.
    //
    // This list is the contract. Adding a money-moving endpoint without a scope
    // should fail here rather than in production.
    $expected = [
        'commerce.checkout',
        'dispatch.accept',
        'marketplace.checkout',
        'payments.initiate',
        'payments.refund',
        // M41. A subscription is a standing charge, so a duplicate is not one
        // extra payment but one extra payment every billing period.
        'payments.subscription',
        'payments.wallet.topup',
        'payments.wallet.transfer',
    ];

    $found = [];

    // A real recursive walk. `glob()` does not expand `**`, so an earlier
    // version of this silently scanned nothing and would have passed while
    // asserting an empty list against an empty list — a coverage test that
    // covers nothing is worse than no test.
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(dirname(__DIR__, 3), FilesystemIterator::SKIP_DOTS),
    );

    foreach ($files as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        // Tests reference scopes too; only production code defines them.
        if (str_contains($file->getPathname(), '/tests/')) {
            continue;
        }

        $source = file_get_contents($file->getPathname());

        if ($source === false || ! str_contains($source, 'idempotency->execute(')) {
            continue;
        }

        preg_match_all("/idempotency->execute\(\s*'([a-z][a-z_.]*)'/", $source, $matches);
        $found = array_merge($found, $matches[1]);
    }

    expect($found)->not->toBeEmpty('The scan found no idempotency scopes at all — the walk is broken.');

    $found = array_values(array_unique($found));
    sort($found);

    expect($found)->toBe($expected);
});

// --------------------------------------------------------- crash recovery

it('releases a claim when the work throws, so a corrected retry can proceed', function (): void {
    $store = app(IdempotencyStore::class);

    expect(fn () => $store->execute('test.scope', 'k1', 'hash', function (): array {
        throw new RuntimeException('provider rejected it');
    }))->toThrow(RuntimeException::class);

    // The claim is gone, not stuck. A caller who fixed the problem is not
    // blocked by their own failed attempt.
    expect(IdempotencyKeyModel::query()->where('idempotency_key', 'k1')->exists())->toBeFalse();

    $result = $store->execute('test.scope', 'k1', 'hash', fn (): array => ['ok' => true]);

    expect($result->value)->toBe(['ok' => true])
        ->and($result->replayed)->toBeFalse();
});

it('replays the stored result rather than doing the work twice', function (): void {
    $store = app(IdempotencyStore::class);
    $runs = 0;

    $work = function () use (&$runs): array {
        $runs++;

        return ['charged' => true];
    };

    $first = $store->execute('test.scope', 'k2', 'hash', $work);
    $second = $store->execute('test.scope', 'k2', 'hash', $work);

    expect($runs)->toBe(1)
        ->and($first->replayed)->toBeFalse()
        ->and($second->replayed)->toBeTrue()
        ->and($second->value)->toBe(['charged' => true]);
});

it('refuses a key reused for a different request', function (): void {
    // The guard against a client recycling keys: replaying an unrelated stored
    // response would be worse than either executing or refusing.
    $store = app(IdempotencyStore::class);
    $store->execute('test.scope', 'k3', 'hash-a', fn (): array => ['ok' => true]);

    expect(fn () => $store->execute('test.scope', 'k3', 'hash-b', fn (): array => ['ok' => true]))
        ->toThrow(IdempotencyConflict::class);
});

it('blocks a retry while the original is still in flight', function (): void {
    // A hard crash leaves the claim behind with no result. Until it expires,
    // another attempt must be refused rather than executed — the refusal costs
    // a round trip, executing costs a duplicate charge.
    $store = app(IdempotencyStore::class);

    IdempotencyKeyModel::query()->create([
        'id' => (string) Str::uuid(),
        'scope' => 'test.scope',
        'idempotency_key' => 'k4',
        'request_hash' => 'hash',
        'state' => IdempotencyKeyModel::STATE_IN_PROGRESS,
        'created_at' => now(),
        'expires_at' => now()->addDay(),
    ]);

    expect(fn () => $store->execute('test.scope', 'k4', 'hash', fn (): array => ['ok' => true]))
        ->toThrow(IdempotencyConflict::class);
});

it('reclaims a claim abandoned by a crashed process once it expires', function (): void {
    // The recovery path. Without it a crashed request would block its own key
    // for ever and the customer could never retry.
    $store = app(IdempotencyStore::class);

    IdempotencyKeyModel::query()->create([
        'id' => (string) Str::uuid(),
        'scope' => 'test.scope',
        'idempotency_key' => 'k5',
        'request_hash' => 'hash',
        'state' => IdempotencyKeyModel::STATE_IN_PROGRESS,
        'created_at' => now()->subDays(2),
        'expires_at' => now()->subHour(),
    ]);

    $result = $store->execute('test.scope', 'k5', 'hash', fn (): array => ['recovered' => true]);

    expect($result->value)->toBe(['recovered' => true])
        ->and($result->replayed)->toBeFalse();
});

it('runs the work unguarded when the caller supplies no key', function (): void {
    // Idempotency is opt-in per request, so an endpoint can adopt it without
    // breaking callers that do not send the header.
    $store = app(IdempotencyStore::class);
    $runs = 0;

    $work = function () use (&$runs): array {
        $runs++;

        return ['ok' => true];
    };

    $store->execute('test.scope', null, 'hash', $work);
    $store->execute('test.scope', null, 'hash', $work);

    expect($runs)->toBe(2)
        ->and(IdempotencyKeyModel::query()->count())->toBe(0);
});

it('purges expired keys without touching live ones', function (): void {
    $store = app(EloquentIdempotencyStore::class);
    $now = app(Clock::class)->now();

    foreach ([['live', '+1 day'], ['dead', '-1 day']] as [$key, $offset]) {
        IdempotencyKeyModel::query()->create([
            'id' => (string) Str::uuid(),
            'scope' => 'test.scope',
            'idempotency_key' => $key,
            'request_hash' => 'hash',
            'state' => IdempotencyKeyModel::STATE_COMPLETED,
            'created_at' => $now,
            'expires_at' => $now->modify($offset),
        ]);
    }

    expect($store->purgeExpired())->toBe(1)
        ->and(IdempotencyKeyModel::query()->pluck('idempotency_key')->all())->toBe(['live']);
});
