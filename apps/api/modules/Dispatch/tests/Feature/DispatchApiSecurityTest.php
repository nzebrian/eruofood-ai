<?php

declare(strict_types=1);

use EruoFood\Admin\Domain\Enum\AdminRole;
use EruoFood\Admin\Domain\Rbac\AdminAccount;
use EruoFood\Admin\Domain\Rbac\AdminAccountRepository;
use EruoFood\Dispatch\Application\Service\AssignmentService;
use EruoFood\Dispatch\Domain\Enum\OfferState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * M26 — the HTTP surfaces, and what they refuse.
 *
 * Four properties, each of which would be a real harm if it failed:
 *
 * **A rider can only touch their own things.** An offer id and an assignment id
 * are UUIDs in a path. Without the ownership check, anybody holding one could
 * accept somebody else's delivery or mark it delivered.
 *
 * **A rider cannot assign themselves.** No endpoint accepts a rider id, a
 * delivery id to claim, or a request id. Self-assignment is not forbidden by a
 * rule that could be forgotten — it is unexpressible.
 *
 * **A rider cannot move the map.** No dispatch endpoint takes coordinates. A
 * rider who could post their own position to a dispatch endpoint could put
 * themselves outside every restaurant in Lagos at once.
 *
 * **Reading and changing are different permissions.** `dispatch.read` sees the
 * queue; `dispatch.manage` takes a delivery off one rider and gives it to
 * another. A single permission would have handed the second to everyone who
 * needed the first.
 */

/** @return array{token: string, userId: string, riderId: string} */
function apiRider(object $test, string $email, array $adminRoles = []): array
{
    Mail::fake();

    $data = $test->postJson('/api/v1/auth/register', [
        'name' => 'API Rider',
        'email' => $email,
        'password' => 'Password123',
        'password_confirmation' => 'Password123',
    ])->assertCreated()->json('data');

    $userId = (string) $data['user']['id'];
    $riderId = (string) Str::orderedUuid();

    DB::table('marketplace_riders')->insert([
        'id' => $riderId,
        'user_id' => $userId,
        'name' => 'API Rider',
        'phone' => '+234800'.random_int(1_000_000, 9_999_999),
        'vehicle_type' => 'motorbike',
        'status' => 'online',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    if ($adminRoles !== []) {
        app(AdminAccountRepository::class)->save(
            AdminAccount::grant($userId, $adminRoles, new DateTimeImmutable()),
        );
    }

    locate($riderId, $userId);
    approveVehicleFor($riderId, new DateTimeImmutable('+1 year'));

    return ['token' => (string) $data['tokens']['access_token'], 'userId' => $userId, 'riderId' => $riderId];
}

/*
|------------------------------------------------------------------------------
| Rider surfaces
|------------------------------------------------------------------------------
*/

it('refuses every rider endpoint without a token', function (string $method, string $path): void {
    $this->json($method, $path)->assertUnauthorized();
})->with([
    ['GET', '/api/v1/dispatch/offers/current'],
    ['GET', '/api/v1/dispatch/assignments/current'],
    ['GET', '/api/v1/dispatch/vehicles'],
    ['POST', '/api/v1/dispatch/vehicles'],
]);

it('shows a rider the offer that was made to them', function (): void {
    $rider = apiRider($this, 'offer-owner@example.test');
    $request = liveRequest();
    $offer = offerTo($request, $rider['riderId']);

    $this->withToken($rider['token'])
        ->getJson('/api/v1/dispatch/offers/current')
        ->assertOk()
        ->assertJsonPath('data.offer.id', $offer->id());
});

/**
 * The IDOR test. An offer id is a UUID in a path.
 */
it('refuses to let a rider answer an offer made to somebody else', function (): void {
    $owner = apiRider($this, 'idor-owner@example.test');
    $stranger = apiRider($this, 'idor-stranger@example.test');

    $request = liveRequest();
    $offer = offerTo($request, $owner['riderId']);

    $this->withToken($stranger['token'])
        ->postJson("/api/v1/dispatch/offers/{$offer->id()}/accept")
        ->assertForbidden();

    $this->withToken($stranger['token'])
        ->postJson("/api/v1/dispatch/offers/{$offer->id()}/decline", ['reason' => 'nope'])
        ->assertForbidden();

    // Untouched by either attempt.
    expect(offerRepo()->find($offer->id())->state())->toBe(OfferState::Offered);
});

it('refuses to let a rider advance somebody else\'s delivery over HTTP', function (): void {
    $owner = apiRider($this, 'adv-owner@example.test');
    $stranger = apiRider($this, 'adv-stranger@example.test');

    $request = liveRequest();
    $assignment = app(AssignmentService::class)->accept(
        $owner['userId'],
        offerTo($request, $owner['riderId'])->id(),
    );

    $this->withToken($stranger['token'])
        ->postJson("/api/v1/dispatch/assignments/{$assignment->id()}/state", ['state' => 'en_route_pickup'])
        ->assertForbidden();
});

it('lets the owning rider accept and walk the journey', function (): void {
    $rider = apiRider($this, 'journey@example.test');
    $request = liveRequest();
    $offer = offerTo($request, $rider['riderId']);

    $assignmentId = $this->withToken($rider['token'])
        ->postJson("/api/v1/dispatch/offers/{$offer->id()}/accept")
        ->assertCreated()
        ->json('data.assignment.id');

    foreach (['en_route_pickup', 'arrived_pickup', 'picked_up', 'in_transit', 'delivered'] as $state) {
        $this->withToken($rider['token'])
            ->postJson("/api/v1/dispatch/assignments/{$assignmentId}/state", ['state' => $state])
            ->assertOk()
            ->assertJsonPath('data.assignment.state', $state);
    }
});

/**
 * The states a rider is not allowed to declare.
 *
 * Asserted as a *validation* error, not merely a 422. An earlier version of
 * this test checked only the status code and passed with every layer of the
 * protection removed — three different layers all answer 422, so the code alone
 * proves nothing about which one is working. `assertJsonValidationErrors`
 * pins the specific rule.
 */
it('refuses a rider trying to cancel or reassign their own delivery', function (string $state): void {
    $rider = apiRider($this, 'self-'.$state.'@example.test');
    $request = liveRequest();
    $assignment = app(AssignmentService::class)->accept(
        $rider['userId'],
        offerTo($request, $rider['riderId'])->id(),
    );

    $this->withToken($rider['token'])
        ->postJson("/api/v1/dispatch/assignments/{$assignment->id()}/state", ['state' => $state])
        ->assertStatus(422)
        ->assertJsonValidationErrors('state');
})->with(['cancelled', 'reassignment_required', 'accepted']);

/**
 * Self-assignment is unexpressible, not merely forbidden.
 */
it('offers a rider no endpoint that could assign them work', function (): void {
    $rider = apiRider($this, 'self-assign@example.test');
    $request = liveRequest();

    // Nothing accepts a request id, a delivery id or a rider id from a rider.
    foreach ([
        "/api/v1/dispatch/requests/{$request->id()}/assign",
        "/api/v1/dispatch/deliveries/{$request->deliveryId()}/claim",
        '/api/v1/dispatch/assignments',
    ] as $path) {
        $this->withToken($rider['token'])
            ->postJson($path, ['rider_id' => $rider['riderId']])
            ->assertNotFound();
    }
});

it('accepts no coordinates on any rider dispatch endpoint', function (): void {
    $rider = apiRider($this, 'no-coords@example.test');
    $request = liveRequest();
    $offer = offerTo($request, $rider['riderId']);

    // A rider posting their own position to a dispatch endpoint could put
    // themselves outside every restaurant in Lagos at once. The fields are
    // simply ignored — the position comes from M25, under M25's authorisation.
    $this->withToken($rider['token'])
        ->postJson("/api/v1/dispatch/offers/{$offer->id()}/accept", [
            'latitude' => 6.5244,
            'longitude' => 3.3792,
        ])
        ->assertCreated();

    $stored = DB::table('geo_rider_locations')->where('rider_id', $rider['riderId'])->first();

    // Unchanged: still the position M25 recorded, not the one the request claimed.
    expect((float) $stored->latitude)->toBe(6.5244)
        ->and((float) $stored->longitude)->toBe(3.3792);
});

it('shows a rider only their own vehicles over HTTP', function (): void {
    $mine = apiRider($this, 'veh-mine@example.test');
    apiRider($this, 'veh-theirs@example.test');

    $this->withToken($mine['token'])
        ->postJson('/api/v1/dispatch/vehicles', ['type' => 'bike'])
        ->assertCreated();

    $listed = $this->withToken($mine['token'])
        ->getJson('/api/v1/dispatch/vehicles')
        ->assertOk()
        ->json('data');

    foreach ($listed as $vehicle) {
        expect($vehicle['rider_id'])->toBe($mine['riderId']);
    }
});

it('gives a rider no route that could approve their own vehicle', function (): void {
    $rider = apiRider($this, 'self-approve@example.test');

    $vehicleId = $this->withToken($rider['token'])
        ->postJson('/api/v1/dispatch/vehicles', ['type' => 'bike'])
        ->assertCreated()
        ->json('data.id');

    // The rider prefix has no approve endpoint at all; the admin one is gated.
    $this->withToken($rider['token'])
        ->postJson("/api/v1/dispatch/vehicles/{$vehicleId}/approve")
        ->assertNotFound();

    $this->withToken($rider['token'])
        ->postJson("/api/v1/admin/dispatch/vehicles/{$vehicleId}/approve")
        ->assertForbidden();

    expect(DB::table('dispatch_vehicles')->where('id', $vehicleId)->value('status'))
        ->toBe('pending_verification');
});

/*
|------------------------------------------------------------------------------
| Control Centre — the read/manage split
|------------------------------------------------------------------------------
*/

it('refuses the control centre surfaces to a rider with no admin role', function (string $path): void {
    $rider = apiRider($this, 'no-admin-'.md5($path).'@example.test');

    $this->withToken($rider['token'])->getJson($path)->assertForbidden();
})->with([
    '/api/v1/admin/dispatch/queue',
    '/api/v1/admin/dispatch/active',
    '/api/v1/admin/dispatch/failures',
    '/api/v1/admin/dispatch/availability',
    '/api/v1/admin/dispatch/health',
    '/api/v1/admin/dispatch/vehicles/queue',
]);

it('lets a support role read the queue but not change anything', function (): void {
    // Customer support answers "where is my order?" — that needs the queue.
    // Taking the delivery off the rider does not.
    $support = apiRider($this, 'support@example.test', [AdminRole::CustomerSupport]);

    $this->withToken($support['token'])
        ->getJson('/api/v1/admin/dispatch/queue')
        ->assertOk();

    $request = liveRequest();

    $this->withToken($support['token'])
        ->postJson("/api/v1/admin/dispatch/requests/{$request->id()}/cancel", ['reason' => 'testing'])
        ->assertForbidden();
});

it('lets an operations role both read and manage', function (): void {
    $ops = apiRider($this, 'ops@example.test', [AdminRole::OperationsManager]);
    $request = liveRequest();

    $this->withToken($ops['token'])->getJson('/api/v1/admin/dispatch/queue')->assertOk();

    $this->withToken($ops['token'])
        ->postJson("/api/v1/admin/dispatch/requests/{$request->id()}/cancel", ['reason' => 'duplicate order'])
        ->assertOk();
});

it('demands a stated reason for every privileged action', function (): void {
    $ops = apiRider($this, 'reasonless@example.test', [AdminRole::OperationsManager]);
    $request = liveRequest();

    // An override with no stated reason is an audit entry nobody can interpret
    // six months later.
    $this->withToken($ops['token'])
        ->postJson("/api/v1/admin/dispatch/requests/{$request->id()}/cancel", [])
        ->assertStatus(422);
});

it('writes an audit entry for every privileged action', function (): void {
    $ops = apiRider($this, 'audited@example.test', [AdminRole::OperationsManager]);
    $rider = apiRider($this, 'audited-rider@example.test');
    $request = liveRequest();

    $this->withToken($ops['token'])
        ->postJson("/api/v1/admin/dispatch/requests/{$request->id()}/assign", [
            'rider_id' => $rider['riderId'],
            'reason' => 'Rider is standing at the counter.',
        ])
        ->assertCreated();

    $entry = DB::table('admin_audit_log')
        ->where('action', 'dispatch.manual_assignment')
        ->where('subject_id', $request->id())
        ->first();

    expect($entry)->not->toBeNull()
        ->and($entry->actor_id)->toBe($ops['userId'])
        // The reason is in the trail, not just in the operator's head.
        ->and((string) $entry->context)->toContain('Rider is standing at the counter.');
});

/**
 * An operational dashboard needs to know the fleet is thin. It does not need to
 * be a live map of where a workforce is standing.
 */
it('puts no coordinate on any control centre dispatch surface', function (string $path): void {
    $ops = apiRider($this, 'coords-'.md5($path).'@example.test', [AdminRole::OperationsManager]);
    liveRequest();

    $body = $this->withToken($ops['token'])->getJson($path)->assertOk()->content();

    expect($body)->not->toContain('latitude')
        ->not->toContain('longitude')
        ->not->toContain('6.5244')
        ->not->toContain('3.3792');
})->with([
    '/api/v1/admin/dispatch/queue',
    '/api/v1/admin/dispatch/active',
    '/api/v1/admin/dispatch/availability',
    '/api/v1/admin/dispatch/health',
]);

it('reports dispatch health with the engine switch visible', function (): void {
    $ops = apiRider($this, 'health@example.test', [AdminRole::OperationsManager]);

    $this->withToken($ops['token'])
        ->getJson('/api/v1/admin/dispatch/health')
        ->assertOk()
        // Ships off. An operator should be able to see that at a glance rather
        // than infer it from an empty queue.
        ->assertJsonPath('data.engine_enabled', false)
        ->assertJsonStructure(['data' => ['searches_waiting', 'oldest_wait_seconds', 'availability']]);
});
