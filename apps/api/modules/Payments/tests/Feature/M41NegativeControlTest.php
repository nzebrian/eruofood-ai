<?php

declare(strict_types=1);

use EruoFood\Payments\Application\Service\SubscriptionService;
use EruoFood\Payments\Infrastructure\Persistence\Eloquent\Model\SubscriptionModel;
use EruoFood\Shared\Domain\Idempotency\IdempotencyStore;
use EruoFood\Shared\Domain\Idempotency\IdempotentResult;
use EruoFood\Shared\Infrastructure\Idempotency\Model\IdempotencyKeyModel;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * M41 — controls on the M41 controls.
 *
 * `SubscriptionIdempotencyTest` passes. So would a version of it that asserted
 * nothing, and the property at stake is not cosmetic: a duplicate subscription
 * bills someone every month until a human notices.
 *
 * Each control below RECONSTRUCTS the defect using the real store, the real
 * subscription service and the real table, and proves the paired assertion
 * tells the two apart. The five reversals the M41 contract names:
 *
 *   A. principal-scoped key derivation removed
 *   B. the request fingerprint comparison removed
 *   C. the database uniqueness protection removed
 *   D. the idempotency layer bypassed entirely
 *   E. a duplicate created by a read-then-write race
 *
 * ## Limitation, stated plainly
 *
 * These reconstruct each defect at its seam — they build the collaborator the
 * broken code would have used and show the assertion discriminates. They do NOT
 * prove that an arbitrary future edit to the production files is caught by a
 * separate process, and no file-level mutation run was performed for M41.
 * Control E in particular reconstructs a race by interleaving two callers in one
 * process; it does not execute two simultaneous HTTP requests.
 */

/** Fields shared by claim rows written directly in these controls. */
function claimRow(string $key, string $hash, ?string $userId = null): array
{
    return [
        'id' => (string) Str::orderedUuid(),
        'scope' => 'payments.subscription',
        'idempotency_key' => $key,
        'request_hash' => $hash,
        'user_id' => $userId,
        'state' => IdempotencyKeyModel::STATE_IN_PROGRESS,
        'created_at' => now(),
        'expires_at' => now()->addDay(),
    ];
}

/** The fingerprint the controller computes, in its pre-M41 and shipped shapes. */
function fingerprintOf(array $payload): string
{
    ksort($payload);

    return hash('sha256', (string) json_encode($payload, JSON_THROW_ON_ERROR));
}

// =============================================================================
// C. The database uniqueness protection
// =============================================================================

it('M41 · C · proves the unique index, not the application, is what blocks the second claim', function (): void {
    $key = derivedKey('11111111-1111-4111-8111-111111111111', 'control-c');
    $hash = str_repeat('a', 64);

    // The race-prone pattern the design forbids, played out against the REAL
    // table: both callers look first, both see nothing, then both write.
    $callerASees = IdempotencyKeyModel::query()->where('idempotency_key', $key)->exists();
    $callerBSees = IdempotencyKeyModel::query()->where('idempotency_key', $key)->exists();

    expect($callerASees)->toBeFalse()->and($callerBSees)->toBeFalse();

    IdempotencyKeyModel::query()->create(claimRow($key, $hash));

    // Caller B's check already passed. Only the constraint stops it now — if
    // the index were dropped, B's insert would succeed and two requests would
    // each believe they owned the key.
    //
    // The losing insert is wrapped exactly as `EloquentIdempotencyStore` wraps
    // its own: on PostgreSQL a constraint violation aborts the *enclosing*
    // transaction, which under RefreshDatabase is the whole test, so every
    // later statement — including the count below — would fail with "current
    // transaction is aborted". Nesting makes it a SAVEPOINT, so only the losing
    // insert rolls back.
    expect(fn () => DB::transaction(fn () => IdempotencyKeyModel::query()->create(claimRow($key, $hash))))
        ->toThrow(UniqueConstraintViolationException::class);

    expect(IdempotencyKeyModel::query()->count())->toBe(1);
});

it('M41 · C · false-positive control — the table rejects duplicate keys, not every second row', function (): void {
    // If the write above failed for some unrelated reason, the control would be
    // vacuous. Two rows identical in every column EXCEPT the key insert fine,
    // so the rejection is attributable to (scope, idempotency_key) and nothing
    // else.
    $hash = str_repeat('c', 64);
    $user = '22222222-2222-4222-8222-222222222222';

    IdempotencyKeyModel::query()->create(claimRow(derivedKey($user, 'control-c1'), $hash, $user));
    IdempotencyKeyModel::query()->create(claimRow(derivedKey($user, 'control-c2'), $hash, $user));

    expect(IdempotencyKeyModel::query()->count())->toBe(2);
});

// =============================================================================
// B. The request fingerprint comparison
// =============================================================================

/**
 * The real store with its fingerprint comparison neutralised.
 *
 * Every caller is handed the same constant hash, which is what "the comparison
 * was removed" amounts to: the store can no longer tell a retry from a
 * different request wearing the same key.
 */
final class FingerprintBlindStore implements IdempotencyStore
{
    public function __construct(private readonly IdempotencyStore $inner)
    {
    }

    public function execute(string $scope, ?string $key, string $requestHash, callable $work, ?string $principalId = null): IdempotentResult
    {
        return $this->inner->execute($scope, $key, str_repeat('0', 64), $work, $principalId);
    }

    public function countExpired(): int
    {
        return $this->inner->countExpired();
    }

    public function purgeExpired(int $chunkSize = 1000): int
    {
        return $this->inner->purgeExpired($chunkSize);
    }
}

/*
 * The two halves of control B are separate tests on purpose. Laravel caches the
 * resolved controller on the Route object, so a store swapped into the container
 * after the endpoint has already been hit would not reach the controller — and
 * the "regressed" half would quietly re-run the fixed code and pass for the
 * wrong reason. Swapping before the first request is the only ordering that
 * actually exercises the reconstruction.
 */

it('M41 · B · the shipped path refuses a key already spent on a different plan', function (): void {
    $user = subscriber($this, 'control-b@example.com');

    startSubscription($this, $user['token'], 'control-b', planPayload(250000))->assertCreated();
    startSubscription($this, $user['token'], 'control-b', planPayload(950000))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED');

    expect(subscriptionCount())->toBe(1)
        ->and(SubscriptionModel::query()->sole()->amount_minor)->toBe(250000);
});

it('M41 · B · proves that refusal is the fingerprint comparison and nothing else', function (): void {
    // Same controller, same store, same table — only the comparison disabled.
    $this->app->instance(IdempotencyStore::class, new FingerprintBlindStore(app(IdempotencyStore::class)));

    $user = subscriber($this, 'control-b2@example.com');

    startSubscription($this, $user['token'], 'control-b2', planPayload(250000))->assertCreated();

    // The ₦9,500 request is answered with the ₦2,500 subscription. That is the
    // outcome the assertion in the paired test rules out; without the
    // comparison, nothing else on the path would have caught it.
    $wrong = startSubscription($this, $user['token'], 'control-b2', planPayload(950000))
        ->assertOk()->json('data');

    expect($wrong['amount_minor'])->toBe(250000)
        ->and(subscriptionCount())->toBe(1);
});

// =============================================================================
// A. Principal scoping
// =============================================================================

it('M41 · A · proves an unscoped key hands one user the other user\'s subscription', function (): void {
    $alice = subscriber($this, 'control-a-alice@example.com');
    $bob = subscriber($this, 'control-a-bob@example.com');

    $store = app(IdempotencyStore::class);
    $service = app(SubscriptionService::class);

    // The naive implementation: the raw client key, and a fingerprint of the
    // payload alone. Real store, real service, real table.
    $work = fn (string $userId): callable => fn (): array => [
        'id' => $service->start($userId, 'gold', 250000, 'monthly')->id(),
        'owner' => $userId,
    ];
    $hash = fingerprintOf(planPayload());

    $hers = $store->execute('control.unscoped', 'shared-key', $hash, $work($alice['id']));
    $his = $store->execute('control.unscoped', 'shared-key', $hash, $work($bob['id']));

    // Bob is handed Alice's subscription id, and no subscription of his own is
    // created. This is the leak the shipped derivation exists to prevent.
    expect($his->replayed)->toBeTrue()
        ->and($his->value['id'])->toBe($hers->value['id'])
        ->and($his->value['owner'])->toBe($alice['id'])
        ->and(SubscriptionModel::query()->count())->toBe(1);
});

it('M41 · A · proves the pre-M41 house pattern refuses Bob rather than isolating him', function (): void {
    // Putting the actor in the fingerprint — what payments.initiate, refunds
    // and wallet moves do — stops the leak above. It does NOT give Bob his own
    // claim: he is told the key is spent, on a key he has never used.
    $alice = subscriber($this, 'control-a2-alice@example.com');
    $bob = subscriber($this, 'control-a2-bob@example.com');

    $store = app(IdempotencyStore::class);
    $service = app(SubscriptionService::class);
    $work = fn (string $userId): callable => fn (): array => ['id' => $service->start($userId, 'gold', 250000, 'monthly')->id()];

    $store->execute('control.actor', 'shared-key', fingerprintOf(planPayload() + ['actor' => $alice['id']]), $work($alice['id']));

    expect(fn () => $store->execute('control.actor', 'shared-key', fingerprintOf(planPayload() + ['actor' => $bob['id']]), $work($bob['id'])))
        ->toThrow(EruoFood\Shared\Domain\Exception\IdempotencyConflict::class);

    expect(SubscriptionModel::query()->count())->toBe(1);
});

it('M41 · A · proves the shipped derivation isolates the two instead', function (): void {
    // Same two users, same key value, through the real endpoint. Both succeed,
    // each gets their own subscription, and neither claim is stored under the
    // key the clients sent.
    $alice = subscriber($this, 'control-a3-alice@example.com');
    $bob = subscriber($this, 'control-a3-bob@example.com');

    $hers = startSubscription($this, $alice['token'], 'shared-key')->assertCreated()->json('data.id');
    $his = startSubscription($this, $bob['token'], 'shared-key')->assertCreated()->json('data.id');

    expect($his)->not->toBe($hers)
        ->and(subscriptionCount())->toBe(2)
        ->and(IdempotencyKeyModel::query()->pluck('idempotency_key')->all())->not->toContain('shared-key');
});

// =============================================================================
// D. Bypassing the idempotency layer
// =============================================================================

it('M41 · D · proves the guard, not the service, is what collapses the retry', function (): void {
    $user = subscriber($this, 'control-d@example.com');

    // The pre-M41 controller body: straight to the service, twice. The domain
    // has no opinion about duplicates, so two standing charges appear.
    $service = app(SubscriptionService::class);
    $service->start($user['id'], 'gold', 250000, 'monthly');
    $service->start($user['id'], 'gold', 250000, 'monthly');

    expect(SubscriptionModel::query()->where('user_id', $user['id'])->count())->toBe(2);

    // The same two calls through the guarded endpoint, with a key, produce one.
    $other = subscriber($this, 'control-d2@example.com');
    startSubscription($this, $other['token'], 'control-d')->assertCreated();
    startSubscription($this, $other['token'], 'control-d')->assertOk();

    expect(SubscriptionModel::query()->where('user_id', $other['id'])->count())->toBe(1);
});

// =============================================================================
// E. A duplicate created by a race
// =============================================================================

it('M41 · E · proves a read-then-write check would still duplicate under interleaving', function (): void {
    $user = subscriber($this, 'control-e@example.com');
    $service = app(SubscriptionService::class);

    // The plausible-looking guard: "does this user already have this plan?"
    $alreadyHasPlan = fn (): bool => SubscriptionModel::query()
        ->where('user_id', $user['id'])->where('plan', 'gold')->exists();

    // Two requests interleave in the window between the check and the write —
    // the window a lookup cannot close.
    $requestASees = $alreadyHasPlan();
    $requestBSees = $alreadyHasPlan();

    expect($requestASees)->toBeFalse()->and($requestBSees)->toBeFalse();

    $service->start($user['id'], 'gold', 250000, 'monthly');
    $service->start($user['id'], 'gold', 250000, 'monthly');

    expect(SubscriptionModel::query()->where('user_id', $user['id'])->count())->toBe(2);
});

it('M41 · E · proves the claim-first ordering closes that window', function (): void {
    $user = subscriber($this, 'control-e2@example.com');

    // Request A claims the key and is still running — the same interleaving
    // point as above, reconstructed by rewinding a real completed claim so the
    // stored fingerprint is the one production actually computes.
    startSubscription($this, $user['token'], 'control-e2')->assertCreated();

    SubscriptionModel::query()->delete();
    IdempotencyKeyModel::query()->update([
        'state' => IdempotencyKeyModel::STATE_IN_PROGRESS,
        'completed_at' => null,
        'response_snapshot' => null,
    ]);

    // Request B arrives in exactly the window that duplicated the subscription
    // above, and creates nothing.
    startSubscription($this, $user['token'], 'control-e2')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'IDEMPOTENCY_IN_FLIGHT');

    expect(subscriptionCount())->toBe(0);
});

// =============================================================================
// Positive control
// =============================================================================

it('M41 · positive control — genuinely distinct requests still both succeed', function (): void {
    // The guard must not be "refuse everything". Two different keys are two
    // different intentions, and both get what they asked for.
    $user = subscriber($this, 'control-positive@example.com');

    $first = startSubscription($this, $user['token'], 'control-positive-1')->assertCreated()->json('data');
    $second = startSubscription($this, $user['token'], 'control-positive-2')->assertCreated()->json('data');

    expect($first['status'])->toBe('active')
        ->and($second['status'])->toBe('active')
        ->and($second['id'])->not->toBe($first['id'])
        ->and(subscriptionCount())->toBe(2);

    $this->withToken($user['token'])->getJson('/api/v1/payments/subscriptions')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});
