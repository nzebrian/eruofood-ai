<?php

declare(strict_types=1);

use EruoFood\Payments\Domain\Subscription\Subscription;
use EruoFood\Payments\Domain\Subscription\SubscriptionRepository;
use EruoFood\Payments\Infrastructure\Persistence\Eloquent\Model\SubscriptionModel;
use EruoFood\Shared\Infrastructure\Idempotency\Model\IdempotencyKeyModel;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * M41 — subscription creation is safe to retry.
 *
 * A subscription is a standing instruction to charge someone every week or
 * month. A duplicate is not one extra payment; it is one extra payment every
 * billing period, for as long as nobody notices — and two identical
 * subscriptions for one user look exactly like a customer who wanted two.
 *
 * `POST /payments/subscriptions` was the last customer-facing money-moving
 * endpoint with no idempotency guard, which the M23 coverage audit did not
 * catch because subscriptions were not on its list.
 *
 * Each test below asserts *state* — how many subscriptions exist, which one
 * came back, what the claim row says — rather than that a request was accepted.
 */

/**
 * A registered customer: their id and a bearer token.
 *
 * @return array{id: string, token: string}
 */
function subscriber(object $test, string $email): array
{
    Mail::fake();

    $data = $test->postJson('/api/v1/auth/register', [
        'name' => 'Subscriber',
        'email' => $email,
        'password' => 'Password123',
        'password_confirmation' => 'Password123',
    ])->assertCreated()->json('data');

    return ['id' => $data['user']['id'], 'token' => $data['tokens']['access_token']];
}

/** The payload the endpoint validates. */
function planPayload(int $amountMinor = 250000, string $interval = 'monthly'): array
{
    return ['plan' => 'gold', 'amount_minor' => $amountMinor, 'interval' => $interval];
}

/**
 * Post a subscription, optionally carrying an Idempotency-Key.
 *
 * The header is removed explicitly when no key is wanted: Laravel's test client
 * keeps default headers for the rest of the test, so an omitted key would
 * otherwise silently inherit the previous call's.
 */
function startSubscription(object $test, string $token, ?string $key = null, ?array $payload = null): Illuminate\Testing\TestResponse
{
    $client = $test->withToken($token);
    $client = $key === null
        ? $client->withoutHeader('Idempotency-Key')
        : $client->withHeaders(['Idempotency-Key' => $key]);

    return $client->postJson('/api/v1/payments/subscriptions', $payload ?? planPayload());
}

/** How the controller derives the stored key. Mirrored here to read the claim. */
function derivedKey(string $principalId, string $key): string
{
    return hash('sha256', $principalId."\0".$key);
}

function subscriptionCount(): int
{
    return SubscriptionModel::query()->count();
}

// =============================================================================
// 1-3. The retry path
// =============================================================================

it('creates exactly one subscription on the first keyed request', function (): void {
    $user = subscriber($this, 'sub-first@example.com');

    $body = startSubscription($this, $user['token'], 'key-first')->assertCreated()->json('data');

    expect(subscriptionCount())->toBe(1)
        ->and($body['plan'])->toBe('gold')
        ->and($body['amount_minor'])->toBe(250000)
        ->and($body['status'])->toBe('active');

    // The claim is recorded against the caller, completed, and holds the answer
    // to replay — not merely "a row exists".
    $claim = IdempotencyKeyModel::query()->where('scope', 'payments.subscription')->sole();

    expect($claim->user_id)->toBe($user['id'])
        ->and($claim->state)->toBe(IdempotencyKeyModel::STATE_COMPLETED)
        ->and($claim->response_snapshot['id'])->toBe($body['id'])
        ->and($claim->completed_at)->not->toBeNull();
});

it('does not create a second subscription when the same key and payload are retried', function (): void {
    $user = subscriber($this, 'sub-retry@example.com');

    $first = startSubscription($this, $user['token'], 'key-retry')->assertCreated()->json('data');
    $second = startSubscription($this, $user['token'], 'key-retry')->assertOk()->json('data');

    // One subscription, and the retry is answered 200 rather than 201 — the
    // status is how a client tells "I created it" from "you already had it".
    //
    // `toEqual`, not `toBe`: the replay is read back from a `jsonb` column,
    // which does not preserve key order. Same keys, same values, possibly a
    // different order — which is the guarantee the endpoint actually makes.
    expect(subscriptionCount())->toBe(1)
        ->and($second['id'])->toBe($first['id'])
        ->and($second)->toEqual($first);
});

it('replays the original result rather than a freshly computed one', function (): void {
    // The stored snapshot is the answer, so a retry a month later still names
    // the original next-billing date instead of one computed from "now".
    $user = subscriber($this, 'sub-replay@example.com');

    $first = startSubscription($this, $user['token'], 'key-replay')->assertCreated()->json('data');

    $this->travelTo(now()->addDays(10));

    $second = startSubscription($this, $user['token'], 'key-replay')->assertOk()->json('data');

    expect($second['next_billing_at'])->toBe($first['next_billing_at'])
        ->and(subscriptionCount())->toBe(1);
});

// =============================================================================
// 4. The key reused for something else
// =============================================================================

it('refuses a key already spent on a different payload', function (): void {
    $user = subscriber($this, 'sub-changed@example.com');

    startSubscription($this, $user['token'], 'key-changed')->assertCreated();

    // Same key, different amount. Replaying the stored response would answer a
    // question the client did not ask; executing would create a second standing
    // charge. Both are wrong, so the request is refused.
    $this->assertSame(1, subscriptionCount());

    startSubscription($this, $user['token'], 'key-changed', planPayload(999000))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED');

    expect(subscriptionCount())->toBe(1)
        ->and(SubscriptionModel::query()->sole()->amount_minor)->toBe(250000);
});

it('refuses a key reused for a different interval, not only a different amount', function (): void {
    // The fingerprint must cover every input that materially affects the
    // subscription — a weekly charge is not a monthly one.
    $user = subscriber($this, 'sub-interval@example.com');

    startSubscription($this, $user['token'], 'key-interval', planPayload(250000, 'monthly'))->assertCreated();

    startSubscription($this, $user['token'], 'key-interval', planPayload(250000, 'weekly'))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED');

    expect(subscriptionCount())->toBe(1)
        ->and(SubscriptionModel::query()->sole()->interval)->toBe('monthly');
});

// =============================================================================
// 5-6. Two users, one key value
// =============================================================================

it('never replays one user\'s subscription to another who sends the same key', function (): void {
    $alice = subscriber($this, 'sub-alice@example.com');
    $bob = subscriber($this, 'sub-bob@example.com');

    $hers = startSubscription($this, $alice['token'], 'shared-key')->assertCreated()->json('data');

    // Bob sends the *same key* and the *same payload*. If the key were not
    // bound to the principal, the store would find Alice's completed claim —
    // and, with an identical fingerprint, would hand Bob her subscription.
    $his = startSubscription($this, $bob['token'], 'shared-key')->assertCreated()->json('data');

    expect($his['id'])->not->toBe($hers['id'])
        ->and(subscriptionCount())->toBe(2);

    // And each subscription belongs to the person who asked for it.
    expect(SubscriptionModel::query()->findOrFail($hers['id'])->user_id)->toBe($alice['id'])
        ->and(SubscriptionModel::query()->findOrFail($his['id'])->user_id)->toBe($bob['id']);

    // Two independent claims, each attributed to its own caller, and neither
    // stored under the raw key the clients actually sent.
    $claims = IdempotencyKeyModel::query()->where('scope', 'payments.subscription')->get();

    expect($claims)->toHaveCount(2)
        ->and($claims->pluck('user_id')->sort()->values()->all())
        ->toBe(collect([$alice['id'], $bob['id']])->sort()->values()->all())
        ->and($claims->pluck('idempotency_key')->all())->not->toContain('shared-key')
        ->and($claims->pluck('idempotency_key')->unique())->toHaveCount(2);
});

it('stores the key bound to the principal rather than as the client sent it', function (): void {
    $user = subscriber($this, 'sub-bound@example.com');

    startSubscription($this, $user['token'], 'plain-key')->assertCreated();

    $claim = IdempotencyKeyModel::query()->where('scope', 'payments.subscription')->sole();

    // The raw key never reaches storage — so it cannot leak through a conflict
    // message or a log line, and it cannot collide across principals.
    expect($claim->idempotency_key)->toBe(derivedKey($user['id'], 'plain-key'))
        ->and($claim->idempotency_key)->not->toBe('plain-key')
        ->and(mb_strlen($claim->idempotency_key))->toBe(64);
});

// =============================================================================
// 7-8. Nothing succeeded, so nothing may be replayed as success
// =============================================================================

it('records no claim when validation rejects the request', function (): void {
    $user = subscriber($this, 'sub-invalid@example.com');

    // `interval` is not one of the accepted values.
    $this->withToken($user['token'])
        ->withHeaders(['Idempotency-Key' => 'key-invalid'])
        ->postJson('/api/v1/payments/subscriptions', ['plan' => 'gold', 'amount_minor' => 250000, 'interval' => 'yearly'])
        ->assertStatus(422);

    expect(IdempotencyKeyModel::query()->count())->toBe(0)
        ->and(subscriptionCount())->toBe(0);

    // The key is not burned: the corrected request still works, and is a
    // creation rather than a replay of the failure.
    startSubscription($this, $user['token'], 'key-invalid')->assertCreated();

    expect(subscriptionCount())->toBe(1);
});

it('leaves no successful record behind when persistence fails', function (): void {
    $user = subscriber($this, 'sub-boom@example.com');

    // The real repository, wrapped so its write fails once. Everything else on
    // the path — controller, store, service, entity — stays production code,
    // and this is the case a client cannot distinguish from success.
    $failing = new class (app(SubscriptionRepository::class)) implements SubscriptionRepository {
        public bool $fail = true;

        public function __construct(private readonly SubscriptionRepository $inner)
        {
        }

        public function nextIdentity(): string
        {
            return $this->inner->nextIdentity();
        }

        public function findById(string $id): ?Subscription
        {
            return $this->inner->findById($id);
        }

        public function forUser(string $userId): array
        {
            return $this->inner->forUser($userId);
        }

        public function due(DateTimeImmutable $now): array
        {
            return $this->inner->due($now);
        }

        public function save(Subscription $subscription): void
        {
            if ($this->fail) {
                throw new RuntimeException('database went away mid-write');
            }

            $this->inner->save($subscription);
        }
    };
    $this->app->instance(SubscriptionRepository::class, $failing);

    startSubscription($this, $user['token'], 'key-boom')->assertStatus(500);

    // No subscription, and — the point of the test — no claim that a later
    // retry could be answered from. A stored "success" here would replay a
    // subscription that was never written.
    expect(subscriptionCount())->toBe(0)
        ->and(IdempotencyKeyModel::query()->count())->toBe(0);

    // Once the write works, the same key runs the work for real: the released
    // claim is what makes a corrected retry possible.
    $failing->fail = false;

    startSubscription($this, $user['token'], 'key-boom')->assertCreated();

    expect(subscriptionCount())->toBe(1);
});

// =============================================================================
// 9-10. Key independence and normalisation
// =============================================================================

it('treats different keys as independent requests', function (): void {
    $user = subscriber($this, 'sub-independent@example.com');

    $one = startSubscription($this, $user['token'], 'key-a')->assertCreated()->json('data.id');
    $two = startSubscription($this, $user['token'], 'key-b')->assertCreated()->json('data.id');

    expect($one)->not->toBe($two)
        ->and(subscriptionCount())->toBe(2);
});

it('ignores surrounding whitespace so a padded key is the same key', function (): void {
    $user = subscriber($this, 'sub-space@example.com');

    $first = startSubscription($this, $user['token'], 'key-padded')->assertCreated()->json('data.id');
    $second = startSubscription($this, $user['token'], "  key-padded\t")->assertOk()->json('data.id');

    expect($second)->toBe($first)
        ->and(subscriptionCount())->toBe(1);
});

it('refuses a request that carries no Idempotency-Key at all', function (): void {
    $user = subscriber($this, 'sub-nokey@example.com');

    // The guard is mandatory here, unlike elsewhere on the platform: silently
    // serving the unguarded path would hand a retrying client a second standing
    // charge, so the caller is told instead.
    startSubscription($this, $user['token'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'INVALID_ARGUMENT');

    expect(subscriptionCount())->toBe(0)
        ->and(IdempotencyKeyModel::query()->count())->toBe(0);
});

it('refuses a blank or whitespace-only Idempotency-Key', function (): void {
    $user = subscriber($this, 'sub-empty@example.com');

    // "" and "   " are not keys. Accepting them would let every such caller
    // share one claim, or none — both worse than a refusal.
    foreach (['', '   ', "\t\n"] as $blank) {
        startSubscription($this, $user['token'], $blank)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INVALID_ARGUMENT');
    }

    expect(subscriptionCount())->toBe(0)
        ->and(IdempotencyKeyModel::query()->count())->toBe(0);
});

it('refuses an over-long key instead of truncating it onto another', function (): void {
    $user = subscriber($this, 'sub-long@example.com');

    // 255 is the limit and is accepted.
    startSubscription($this, $user['token'], str_repeat('k', 255))->assertCreated();

    // 256 is refused. Cutting it down to 255 would make two materially
    // different keys claim the same row — the second client would be handed
    // the first one's subscription.
    startSubscription($this, $user['token'], str_repeat('k', 256))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'INVALID_ARGUMENT');

    expect(subscriptionCount())->toBe(1);
});

it('keeps two keys distinct when they differ only beyond the length limit', function (): void {
    $user = subscriber($this, 'sub-prefix@example.com');

    // Both are refused rather than silently collapsed — the property under test
    // is that neither is answered with the other's result.
    startSubscription($this, $user['token'], str_repeat('x', 255).'A')->assertStatus(422);
    startSubscription($this, $user['token'], str_repeat('x', 255).'B')->assertStatus(422);

    expect(subscriptionCount())->toBe(0);
});

// =============================================================================
// 11-12. Where the guarantee actually comes from
// =============================================================================

it('enforces the claim with a database uniqueness constraint, not a lookup', function (): void {
    // The mutex has to be in the schema. A read-then-write check would leave a
    // window in which two concurrent retries both see "no claim yet".
    $unique = collect(Schema::getIndexes('shared_idempotency_keys'))
        ->filter(fn (array $i): bool => (bool) $i['unique'])
        ->map(function (array $i): array {
            // Column order is not the property under test and is not guaranteed
            // to be reported identically by every driver.
            $columns = $i['columns'];
            sort($columns);

            return $columns;
        })
        ->values()
        ->all();

    expect($unique)->toContain(['idempotency_key', 'scope']);

    // And it fires. A second claim on the same (scope, key) cannot be written,
    // whatever the application layer believes.
    $row = [
        'scope' => 'payments.subscription',
        'idempotency_key' => derivedKey('11111111-1111-4111-8111-111111111111', 'dup'),
        'request_hash' => str_repeat('a', 64),
        'state' => IdempotencyKeyModel::STATE_IN_PROGRESS,
        'created_at' => now(),
        'expires_at' => now()->addDay(),
    ];

    IdempotencyKeyModel::query()->create(['id' => (string) Str::orderedUuid()] + $row);

    // Nested so the violation rolls back a SAVEPOINT rather than the enclosing
    // test transaction — on PostgreSQL an unwrapped one aborts everything after
    // it, which is why the store wraps its own claim insert the same way.
    expect(fn () => DB::transaction(
        fn () => IdempotencyKeyModel::query()->create(['id' => (string) Str::orderedUuid()] + $row),
    ))->toThrow(UniqueConstraintViolationException::class);

    expect(IdempotencyKeyModel::query()->count())->toBe(1);
});

it('refuses a duplicate that arrives while the first is still in flight', function (): void {
    $user = subscriber($this, 'sub-inflight@example.com');

    // Two simultaneous HTTP requests cannot be issued from one test process, so
    // the state request B would find is reconstructed instead: request A runs
    // for real — writing a genuine claim, with the fingerprint production code
    // actually computes — and is then rewound to the moment before it finished.
    // Faking the row outright would let a wrong fingerprint pass as a conflict.
    startSubscription($this, $user['token'], 'key-racing')->assertCreated();

    SubscriptionModel::query()->delete();
    IdempotencyKeyModel::query()->where('scope', 'payments.subscription')->update([
        'state' => IdempotencyKeyModel::STATE_IN_PROGRESS,
        'completed_at' => null,
        'response_snapshot' => null,
    ]);

    expect(subscriptionCount())->toBe(0);

    startSubscription($this, $user['token'], 'key-racing')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'IDEMPOTENCY_IN_FLIGHT');

    // The losing request created nothing. That is the property that matters;
    // being told to retry is merely how it is reported.
    expect(subscriptionCount())->toBe(0);
});

it('does not leak the client\'s key back in the conflict message', function (): void {
    $user = subscriber($this, 'sub-quiet@example.com');

    startSubscription($this, $user['token'], 'secret-looking-key')->assertCreated();

    $message = startSubscription($this, $user['token'], 'secret-looking-key', planPayload(700000))
        ->assertStatus(422)
        ->json('error.message');

    expect($message)->not->toContain('secret-looking-key');
});

// =============================================================================
// 13-14. Nothing else changed
// =============================================================================

it('leaves the rest of the subscription surface working', function (): void {
    $user = subscriber($this, 'sub-surface@example.com');

    $created = startSubscription($this, $user['token'], 'key-surface')->assertCreated()->json('data');

    // The keyed response carries the same fields the unkeyed one always did.
    expect(array_keys($created))
        ->toBe(['id', 'plan', 'amount_minor', 'currency', 'interval', 'status', 'next_billing_at']);

    $listed = $this->withToken($user['token'])->getJson('/api/v1/payments/subscriptions')
        ->assertOk()->json('data');

    expect($listed)->toHaveCount(1)->and($listed[0]['id'])->toBe($created['id']);

    $cancelled = $this->withToken($user['token'])
        ->postJson('/api/v1/payments/subscriptions/'.$created['id'].'/cancel')
        ->assertOk()->json('data');

    expect($cancelled['status'])->toBe('cancelled')
        ->and(subscriptionCount())->toBe(1);
});

it('leaves the existing payment idempotency behaviour intact', function (): void {
    $user = subscriber($this, 'sub-payments@example.com');

    $payload = [
        'amount_minor' => 1000000,
        'customer_email' => 'sub-payments@example.com',
        'order_id' => '00000000-0000-0000-0000-0000000000aa',
    ];

    $first = $this->withToken($user['token'])->withHeaders(['Idempotency-Key' => 'pay-key'])
        ->postJson('/api/v1/payments/payments', $payload)->assertCreated()->json('data');

    $second = $this->withToken($user['token'])->withHeaders(['Idempotency-Key' => 'pay-key'])
        ->postJson('/api/v1/payments/payments', $payload)->assertOk()->json('data');

    expect($second['payment_id'])->toBe($first['payment_id'])
        ->and($second)->toEqual($first);

    // `payments.initiate` still claims under the raw key with no principal
    // recorded — M41 changed the subscription path, not this one.
    $claim = IdempotencyKeyModel::query()->where('scope', 'payments.initiate')->sole();

    expect($claim->idempotency_key)->toBe('pay-key')
        ->and($claim->user_id)->toBeNull();
});
