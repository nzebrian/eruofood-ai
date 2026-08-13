<?php

declare(strict_types=1);

use EruoFood\Shared\Domain\Exception\IdempotencyConflict;
use EruoFood\Shared\Domain\Idempotency\IdempotencyStore;
use EruoFood\Shared\Infrastructure\Idempotency\Model\IdempotencyKeyModel;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** M23 — replay protection for money-moving requests. */
it('runs the work once and replays the stored answer for a repeat', function (): void {
    $store = app(IdempotencyStore::class);
    $runs = 0;

    $work = function () use (&$runs): array {
        $runs++;

        return ['order_id' => 'ord-1', 'total' => 5000];
    };

    $first = $store->execute('test.checkout', 'key-1', 'hash-a', $work);
    $second = $store->execute('test.checkout', 'key-1', 'hash-a', $work);

    // toEqual, not toBe: the snapshot round-trips through a jsonb column and
    // PostgreSQL does not preserve object key order, so the replay is
    // key-for-key equal but not necessarily in the original order. That is
    // immaterial to a JSON response.
    expect($runs)->toBe(1)
        ->and($first->replayed)->toBeFalse()
        ->and($second->replayed)->toBeTrue()
        ->and($second->value)->toEqual($first->value);
});

it('refuses a key that was already spent on a different request', function (): void {
    $store = app(IdempotencyStore::class);

    $store->execute('test.checkout', 'key-2', 'hash-a', fn (): array => ['ok' => true]);

    expect(fn () => $store->execute('test.checkout', 'key-2', 'hash-b', fn (): array => ['ok' => true]))
        ->toThrow(IdempotencyConflict::class);
});

it('scopes keys per operation so the same value is reusable elsewhere', function (): void {
    $store = app(IdempotencyStore::class);
    $runs = 0;

    $work = function () use (&$runs): array {
        $runs++;

        return ['n' => $runs];
    };

    $store->execute('test.checkout', 'shared-key', 'hash-a', $work);
    $store->execute('test.refund', 'shared-key', 'hash-a', $work);

    expect($runs)->toBe(2);
});

it('releases the claim when the work fails so a corrected retry can proceed', function (): void {
    $store = app(IdempotencyStore::class);

    expect(fn () => $store->execute('test.checkout', 'key-3', 'hash-a', function (): array {
        throw new RuntimeException('downstream failed');
    }))->toThrow(RuntimeException::class);

    expect(IdempotencyKeyModel::query()->where('idempotency_key', 'key-3')->exists())->toBeFalse();

    $retry = $store->execute('test.checkout', 'key-3', 'hash-a', fn (): array => ['ok' => true]);
    expect($retry->replayed)->toBeFalse()->and($retry->value)->toBe(['ok' => true]);
});

it('runs every time when no key is supplied', function (): void {
    $store = app(IdempotencyStore::class);
    $runs = 0;

    $work = function () use (&$runs): array {
        $runs++;

        return ['n' => $runs];
    };

    $store->execute('test.checkout', null, 'hash-a', $work);
    $store->execute('test.checkout', null, 'hash-a', $work);

    expect($runs)->toBe(2)
        ->and(IdempotencyKeyModel::query()->count())->toBe(0);
});

it('reports a duplicate that is still in flight rather than running it twice', function (): void {
    $store = app(IdempotencyStore::class);

    // A claim left behind by a request that has not finished — exactly what a
    // concurrent duplicate would find.
    $row = new IdempotencyKeyModel();
    $row->id = (string) Illuminate\Support\Str::orderedUuid();
    $row->scope = 'test.checkout';
    $row->idempotency_key = 'key-4';
    $row->request_hash = 'hash-a';
    $row->state = IdempotencyKeyModel::STATE_IN_PROGRESS;
    $row->created_at = now();
    $row->expires_at = now()->addDay();
    $row->save();

    expect(fn () => $store->execute('test.checkout', 'key-4', 'hash-a', fn (): array => ['ok' => true]))
        ->toThrow(IdempotencyConflict::class);
});

it('reclaims an abandoned key once it has expired', function (): void {
    $store = app(IdempotencyStore::class);

    // A crashed request leaves an in-progress claim. It must not block retries
    // forever — expiry is what bounds the damage.
    $row = new IdempotencyKeyModel();
    $row->id = (string) Illuminate\Support\Str::orderedUuid();
    $row->scope = 'test.checkout';
    $row->idempotency_key = 'key-5';
    $row->request_hash = 'hash-a';
    $row->state = IdempotencyKeyModel::STATE_IN_PROGRESS;
    $row->created_at = now()->subDays(2);
    $row->expires_at = now()->subDay();
    $row->save();

    $result = $store->execute('test.checkout', 'key-5', 'hash-a', fn (): array => ['ok' => true]);

    expect($result->replayed)->toBeFalse()->and($result->value)->toBe(['ok' => true]);
});
