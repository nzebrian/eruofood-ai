<?php

declare(strict_types=1);

use EruoFood\Geo\Application\Service\RiderLocationService;
use EruoFood\Geo\Domain\ValueObject\Coordinates;
use EruoFood\Geo\Infrastructure\Persistence\Eloquent\Model\RiderLocationModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * M25 — rider positions, which are the most sensitive data in this context.
 *
 * Three properties, each of which would be a real harm if it failed:
 *
 * **A rider writes only their own position.** Checked against the rider record,
 * not trusted from the request — a rider id is a UUID in a URL, and without
 * that check anybody holding one could move somebody else's marker across
 * Lagos.
 *
 * **No history accumulates.** One row per rider, overwritten. A movement trail
 * is what live tracking will need in a later milestone and nothing in M25 reads
 * one; collecting a detailed record of everywhere every rider goes, for no
 * current purpose, is textbook over-collection.
 *
 * **Going offline forgets.** A real delete, unlike almost everything else on
 * the platform. There is nothing to audit in a position a rider is entitled to
 * stop sharing.
 */

/** @return array{token: string, id: string, riderId: string} */
function riderAccount(object $test, string $email): array
{
    Mail::fake();

    $data = $test->postJson('/api/v1/auth/register', [
        'name' => 'Test Rider',
        'email' => $email,
        'password' => 'Password123',
        'password_confirmation' => 'Password123',
    ])->assertCreated()->json('data');

    $riderId = (string) Str::orderedUuid();

    DB::table('marketplace_riders')->insert([
        'id' => $riderId,
        'user_id' => $data['user']['id'],
        'name' => 'Test Rider',
        'phone' => '+2348012345678',
        'vehicle_type' => 'motorbike',
        'status' => 'available',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return ['token' => $data['tokens']['access_token'], 'id' => $data['user']['id'], 'riderId' => $riderId];
}

// -------------------------------------------------------------- ownership

it('lets a rider report their own position', function (): void {
    $rider = riderAccount($this, 'rider-own@example.com');

    $response = $this->withToken($rider['token'])
        ->postJson('/api/v1/geo/riders/'.$rider['riderId'].'/location', [
            'latitude' => 6.4550,
            'longitude' => 3.3841,
            'accuracy_metres' => 12.5,
            'heading_degrees' => 91.0,
        ])
        ->assertStatus(202)
        ->json('data');

    expect($response['rider_id'])->toBe($rider['riderId'])
        ->and($response['coordinates']['latitude'])->toBe(6.455)
        ->and($response['accuracy_metres'])->toBe(12.5)
        // The field whose absence made the pre-M25 columns unusable.
        ->and($response['recorded_at'])->not->toBeNull()
        ->and($response['is_stale'])->toBeFalse();
});

/**
 * The check that stops a rider id in a URL from being a licence to move
 * somebody else's marker.
 */
it('refuses to let one rider report another rider\'s position', function (): void {
    $mine = riderAccount($this, 'rider-a@example.com');
    $theirs = riderAccount($this, 'rider-b@example.com');

    $this->withToken($theirs['token'])
        ->postJson('/api/v1/geo/riders/'.$mine['riderId'].'/location', [
            'latitude' => 6.4550,
            'longitude' => 3.3841,
        ])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'GEO_NOT_AUTHORIZED');

    expect(RiderLocationModel::query()->count())->toBe(0);
});

it('refuses to let one rider read another rider\'s position', function (): void {
    $mine = riderAccount($this, 'rider-c@example.com');
    $theirs = riderAccount($this, 'rider-d@example.com');

    $this->withToken($mine['token'])
        ->postJson('/api/v1/geo/riders/'.$mine['riderId'].'/location', ['latitude' => 6.455, 'longitude' => 3.384])
        ->assertStatus(202);

    $this->withToken($theirs['token'])
        ->getJson('/api/v1/geo/riders/'.$mine['riderId'].'/location')
        ->assertStatus(403);
});

it('reports an unknown rider as not found rather than unauthorised', function (): void {
    $rider = riderAccount($this, 'rider-e@example.com');

    $this->withToken($rider['token'])
        ->postJson('/api/v1/geo/riders/'.Str::orderedUuid().'/location', ['latitude' => 6.455, 'longitude' => 3.384])
        ->assertStatus(404);
});

it('requires authentication to report a position at all', function (): void {
    $rider = riderAccount($this, 'rider-f@example.com');

    $this->postJson('/api/v1/geo/riders/'.$rider['riderId'].'/location', ['latitude' => 6.455, 'longitude' => 3.384])
        ->assertStatus(401);
});

// ---------------------------------------------------------- no history kept

/**
 * The over-collection guard. Fifty reports leave one row, not fifty.
 */
it('keeps no movement history, however many times a rider reports', function (): void {
    $rider = riderAccount($this, 'rider-history@example.com');

    foreach (range(1, 12) as $i) {
        $this->withToken($rider['token'])
            ->postJson('/api/v1/geo/riders/'.$rider['riderId'].'/location', [
                'latitude' => 6.4550 + ($i / 10_000),
                'longitude' => 3.3841 + ($i / 10_000),
            ])
            ->assertStatus(202);
    }

    expect(RiderLocationModel::query()->count())->toBe(1)
        ->and(RiderLocationModel::query()->first()->latitude)->toBe(6.4562);
});

it('forgets a rider position entirely when they go offline', function (): void {
    $rider = riderAccount($this, 'rider-offline@example.com');

    $this->withToken($rider['token'])
        ->postJson('/api/v1/geo/riders/'.$rider['riderId'].'/location', ['latitude' => 6.455, 'longitude' => 3.384])
        ->assertStatus(202);

    $this->withToken($rider['token'])
        ->deleteJson('/api/v1/geo/riders/'.$rider['riderId'].'/location')
        ->assertNoContent();

    // A real delete: there is nothing to audit in a position a rider is
    // entitled to stop sharing.
    expect(RiderLocationModel::query()->count())->toBe(0);

    $this->withToken($rider['token'])
        ->getJson('/api/v1/geo/riders/'.$rider['riderId'].'/location')
        ->assertStatus(404);
});

// ------------------------------------------------------------- staleness

/**
 * A device clock can be wrong, and a fix stamped in the future would never look
 * stale — so it would be treated as current forever.
 */
it('refuses to accept a position stamped in the future', function (): void {
    $rider = riderAccount($this, 'rider-future@example.com');

    $this->withToken($rider['token'])
        ->postJson('/api/v1/geo/riders/'.$rider['riderId'].'/location', [
            'latitude' => 6.455,
            'longitude' => 3.384,
            'recorded_at' => now()->addHours(3)->toIso8601String(),
        ])
        ->assertStatus(202);

    $recorded = RiderLocationModel::query()->first()->recorded_at;

    expect($recorded->timestamp)->toBeLessThanOrEqual(now()->timestamp + 2);
});

it('excludes a stale rider from a proximity search', function (): void {
    $service = app(RiderLocationService::class);

    $fresh = riderAccount($this, 'rider-fresh@example.com');
    $stale = riderAccount($this, 'rider-stale@example.com');

    $service->report($fresh['riderId'], $fresh['id'], new Coordinates(6.4560, 3.3850));

    // The stale rider is *nearer*, so this cannot pass by accident of ordering.
    $service->report(
        $stale['riderId'],
        $stale['id'],
        new Coordinates(6.4551, 3.3842),
        recordedAt: new DateTimeImmutable('-2 hours'),
    );

    $nearby = $service->nearby(new Coordinates(6.4550, 3.3841), 5_000.0);

    expect($nearby)->toHaveCount(1)
        ->and($nearby[0]['location']->riderId())->toBe($fresh['riderId'])
        ->and($service->activeRiderCount())->toBe(1);
});

// --------------------------------------------------------- no fleet map

/**
 * A real-time map of where a workforce is belongs to dispatch and live
 * tracking, and neither is in M25. Asserting the absence keeps it from being
 * added casually.
 */
it('exposes no endpoint that lists where every rider is', function (): void {
    $rider = riderAccount($this, 'rider-map@example.com');

    foreach (['/api/v1/geo/riders', '/api/v1/geo/riders/locations', '/api/v1/admin/riders/locations'] as $path) {
        $status = $this->withToken($rider['token'])->getJson($path)->getStatusCode();

        expect($status)->toBeIn([404, 405], "Unexpectedly routable: {$path}");
    }
});

// ------------------------------------------------------------ rate limiting

/**
 * A device stuck in a loop would otherwise write thousands of rows a minute.
 * The limit is the difference between a buggy build and a database incident.
 */
it('limits how often one rider may report', function (): void {
    config()->set('geo.limits.rider_location_per_minute', 3);
    app()->forgetInstance(RiderLocationService::class);

    $rider = riderAccount($this, 'rider-limit@example.com');
    $service = app(RiderLocationService::class);

    foreach (range(1, 3) as $ignored) {
        $service->report($rider['riderId'], $rider['id'], new Coordinates(6.455, 3.384));
    }

    expect(fn () => $service->report($rider['riderId'], $rider['id'], new Coordinates(6.455, 3.384)))
        ->toThrow(EruoFood\Geo\Domain\Exception\GeoQuotaExceeded::class);
});
