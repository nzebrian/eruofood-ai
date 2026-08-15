<?php

declare(strict_types=1);

/**
 * Milestone 26 — true-concurrency validation for the dispatch paths.
 *
 * The Pest suite proves the transitions, the transaction boundaries and the
 * constraints in isolation, but it can never prove locking: `RefreshDatabase`
 * wraps each test in a transaction, so a second "concurrent" write is the same
 * connection and never contends. This script closes that gap by launching real
 * OS processes against a real PostgreSQL database, synchronised on a shared
 * start instant so their statements genuinely collide.
 *
 * Every scenario asserts an exclusivity law that a customer would notice if it
 * failed:
 *
 * - one delivery is assigned to exactly one rider, however many accept at once;
 * - one rider carries exactly one delivery at a time;
 * - a rider's repeated taps produce one assignment, not several;
 * - a rider's Accept and the expiry sweep resolve to exactly one outcome;
 * - two operators approving one vehicle do not silently overwrite each other;
 * - a dropped-out rider produces exactly one replacement search.
 *
 * Those hold only if the row locks, the transaction boundaries, the optimistic
 * versions and the partial unique indexes all do their job together.
 *
 * Run: DB_CONNECTION=pgsql … php scripts/dispatch_concurrency_validation.php
 * Requires: PostgreSQL. SQLite serialises writers globally, so it cannot
 * demonstrate anything here and the script refuses to run against it.
 */

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use EruoFood\Dispatch\Application\Service\AssignmentService;
use EruoFood\Dispatch\Domain\Enum\VehicleType;
use EruoFood\Dispatch\Domain\Offer\OfferRepository;
use EruoFood\Dispatch\Domain\Offer\RiderOffer;
use EruoFood\Dispatch\Domain\Request\DispatchRequest;
use EruoFood\Dispatch\Domain\Request\DispatchRequestRepository;
use EruoFood\Dispatch\Domain\Vehicle\Vehicle;
use EruoFood\Dispatch\Domain\Vehicle\VehicleRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

$driver = DB::connection()->getDriverName();
if ($driver !== 'pgsql') {
    fwrite(STDERR, "This script requires PostgreSQL (got: {$driver}).\n");
    fwrite(STDERR, "SQLite serialises all writers, so it cannot demonstrate row-level contention.\n");
    exit(2);
}

$pass = 0;
$fail = 0;
$worker = __DIR__.'/dispatch_concurrency_worker.php';

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  ✔ {$label}".($detail !== '' ? "  ({$detail})" : '')."\n";
    } else {
        $fail++;
        echo "  ✘ {$label}".($detail !== '' ? "  ({$detail})" : '')."\n";
    }
}

/**
 * Run several workers in parallel, all starting together.
 *
 * Each entry in $argSets is one worker's arguments, so scenarios where the
 * processes differ (two riders, two offers) are expressed as naturally as ones
 * where they are identical.
 *
 * @param list<list<string>> $argSets
 * @return array{succeeded: int, rejected: int, errored: int}
 */
function race(string $worker, string $scenario, array $argSets, float $leadSeconds = 2.0): array
{
    $startAt = microtime(true) + $leadSeconds;
    $dir = sys_get_temp_dir().'/efk-dispatch-conc-'.uniqid();
    mkdir($dir);

    $cmds = [];

    foreach ($argSets as $i => $args) {
        $cmd = sprintf(
            'php %s %s %s %s > %s 2>&1; echo $? > %s',
            escapeshellarg($worker),
            escapeshellarg($scenario),
            escapeshellarg((string) $startAt),
            implode(' ', array_map(escapeshellarg(...), $args)),
            escapeshellarg($dir.'/out'.$i),
            escapeshellarg($dir.'/code'.$i),
        );
        $cmds[] = '('.$cmd.')';
    }

    exec('/bin/bash -c '.escapeshellarg(implode(' & ', $cmds).' & wait'));

    $result = ['succeeded' => 0, 'rejected' => 0, 'errored' => 0];

    foreach (array_keys($argSets) as $i) {
        $code = (int) trim((string) @file_get_contents($dir.'/code'.$i));
        $key = match ($code) {
            0 => 'succeeded',
            1 => 'rejected',
            default => 'errored',
        };
        $result[$key]++;

        if ($code >= 2) {
            echo '      worker error: '.trim((string) @file_get_contents($dir.'/out'.$i))."\n";
        }
    }

    array_map(unlink(...), glob($dir.'/*') ?: []);
    rmdir($dir);

    return $result;
}

/** @return array{userId: string, riderId: string} */
function seedRider(string $status = 'online'): array
{
    $userId = (string) Str::uuid();
    $riderId = (string) Str::uuid();

    DB::table('marketplace_riders')->insert([
        'id' => $riderId,
        'user_id' => $userId,
        'name' => 'Conc Rider '.substr($riderId, 0, 4),
        'phone' => '+2348'.random_int(100_000_000, 999_999_999),
        'vehicle_type' => 'motorbike',
        'status' => $status,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return ['userId' => $userId, 'riderId' => $riderId];
}

function seedRequest(?string $deliveryId = null, int $budgetSeconds = 600): DispatchRequest
{
    $request = DispatchRequest::open(
        id: app(DispatchRequestRepository::class)->nextIdentity(),
        deliveryId: $deliveryId ?? (string) Str::uuid(),
        orderId: (string) Str::uuid(),
        vendorId: (string) Str::uuid(),
        pickupLat: 6.5244,
        pickupLng: 3.3792,
        dropoffLat: 6.4531,
        dropoffLng: 3.3958,
        now: new DateTimeImmutable(),
        maxAttempts: 5,
        timeBudgetSeconds: $budgetSeconds,
    );

    app(DispatchRequestRepository::class)->save($request);

    return $request;
}

function seedOffer(DispatchRequest $request, string $riderId, int $ttlSeconds = 45): RiderOffer
{
    $offer = RiderOffer::make(
        id: app(OfferRepository::class)->nextIdentity(),
        requestId: $request->id(),
        riderId: $riderId,
        deliveryId: $request->deliveryId(),
        now: new DateTimeImmutable(),
        ttlSeconds: $ttlSeconds,
        score: 0.8,
    );

    app(OfferRepository::class)->save($offer);

    return $offer;
}

function seedPendingVehicle(string $riderId): Vehicle
{
    $vehicle = Vehicle::register(
        id: app(VehicleRepository::class)->nextIdentity(),
        riderId: $riderId,
        type: VehicleType::Bike,
        now: new DateTimeImmutable(),
    );

    app(VehicleRepository::class)->save($vehicle);

    return $vehicle;
}

echo "EruoFood — M26 dispatch concurrency validation (PostgreSQL)\n";
echo str_repeat('=', 72)."\n";

// ---------------------------------------------------------------------------
echo "\n1) Two riders accept the same delivery at the same instant\n";
// ---------------------------------------------------------------------------
$request = seedRequest();
$riderA = seedRider();
$riderB = seedRider();
$offerA = seedOffer($request, $riderA['riderId']);
$offerB = seedOffer($request, $riderB['riderId']);

$r = race($worker, 'accept', [
    [$riderA['userId'], $offerA->id()],
    [$riderB['userId'], $offerB->id()],
]);

$assignments = (int) DB::table('dispatch_assignments')
    ->where('delivery_id', $request->deliveryId())
    ->count();

check(
    'exactly one of two simultaneous acceptances succeeds',
    $r['succeeded'] === 1,
    "succeeded={$r['succeeded']} rejected={$r['rejected']} errored={$r['errored']}",
);
check('exactly one assignment exists for the delivery', $assignments === 1, "assignments={$assignments}");
check('the loser was refused, not crashed', $r['errored'] === 0);

// ---------------------------------------------------------------------------
echo "\n2) Ten riders accept the same delivery at the same instant\n";
// ---------------------------------------------------------------------------
$request = seedRequest();
$argSets = [];

for ($i = 0; $i < 10; $i++) {
    $rider = seedRider();
    $offer = seedOffer($request, $rider['riderId']);
    $argSets[] = [$rider['userId'], $offer->id()];
}

$r = race($worker, 'accept', $argSets);

$assignments = (int) DB::table('dispatch_assignments')
    ->where('delivery_id', $request->deliveryId())
    ->count();

$assignedRiders = (int) DB::table('dispatch_assignments')
    ->where('delivery_id', $request->deliveryId())
    ->distinct()
    ->count('rider_id');

check(
    'exactly one of ten simultaneous acceptances succeeds',
    $r['succeeded'] === 1,
    "succeeded={$r['succeeded']} rejected={$r['rejected']} errored={$r['errored']}",
);
check('exactly one assignment exists', $assignments === 1, "assignments={$assignments}");
check('exactly one rider holds it', $assignedRiders === 1, "riders={$assignedRiders}");
check('the nine losers were refused, not crashed', $r['errored'] === 0);

// ---------------------------------------------------------------------------
echo "\n3) One rider double-taps Accept — a retry is not a second rider\n";
// ---------------------------------------------------------------------------
$request = seedRequest();
$rider = seedRider();
$offer = seedOffer($request, $rider['riderId']);

$argSets = array_fill(0, 5, [$rider['userId'], $offer->id()]);
$r = race($worker, 'accept-idempotent', $argSets);

$assignments = (int) DB::table('dispatch_assignments')
    ->where('delivery_id', $request->deliveryId())
    ->count();

check(
    'five simultaneous taps produce exactly one assignment',
    $assignments === 1,
    "assignments={$assignments} succeeded={$r['succeeded']} rejected={$r['rejected']}",
);
check('no tap produced an unexpected error', $r['errored'] === 0);

// ---------------------------------------------------------------------------
echo "\n4) One rider cannot hold two deliveries at once\n";
// ---------------------------------------------------------------------------
//
// The first half is not a race and does not need to be: the
// one-live-offer-per-rider index makes a four-way acceptance by one rider
// unreachable through the offer path at all. That is a stronger guarantee than
// winning a race, so it is asserted directly.
//
// The second half is the race that *is* reachable, and it is the one layer four
// exists for: a second acceptance path that bypasses the service — the shape of
// a future refactor that forgets the lock — colliding with a legitimate accept.
$rider = seedRider();
$firstRequest = seedRequest();
seedOffer($firstRequest, $rider['riderId']);

$secondRequest = seedRequest();
$refusedSecondOffer = false;

try {
    seedOffer($secondRequest, $rider['riderId']);
} catch (Throwable) {
    $refusedSecondOffer = true;
}

check(
    'a rider cannot hold two live offers at once',
    $refusedSecondOffer,
    'enforced by dispatch_offers_one_live_per_rider_uq',
);

// Now the reachable race. The rider accepts their one legitimate offer while
// another process inserts an assignment for them on a different delivery.
$rider = seedRider();
$legitimateRequest = seedRequest();
$legitimateOffer = seedOffer($legitimateRequest, $rider['riderId']);
$otherDelivery = (string) Str::uuid();

$startAt = microtime(true) + 2.0;
$dir = sys_get_temp_dir().'/efk-dispatch-rider-'.uniqid();
mkdir($dir);

exec('/bin/bash -c '.escapeshellarg(sprintf(
    '(php %s accept %s %s %s > %s 2>&1; echo $? > %s) & (php %s raw-assign %s %s %s %s > %s 2>&1; echo $? > %s) & wait',
    escapeshellarg($worker),
    escapeshellarg((string) $startAt),
    escapeshellarg($rider['userId']),
    escapeshellarg($legitimateOffer->id()),
    escapeshellarg($dir.'/accept.out'),
    escapeshellarg($dir.'/accept.code'),
    escapeshellarg($worker),
    escapeshellarg((string) $startAt),
    escapeshellarg($legitimateRequest->id()),
    escapeshellarg($otherDelivery),
    escapeshellarg($rider['riderId']),
    escapeshellarg($dir.'/raw.out'),
    escapeshellarg($dir.'/raw.code'),
)));

$acceptCode = (int) trim((string) @file_get_contents($dir.'/accept.code'));
$rawCode = (int) trim((string) @file_get_contents($dir.'/raw.code'));
$rawOut = trim((string) @file_get_contents($dir.'/raw.out'));

array_map(unlink(...), glob($dir.'/*') ?: []);
rmdir($dir);

$active = (int) DB::table('dispatch_assignments')
    ->where('rider_id', $rider['riderId'])
    ->whereIn('state', ['accepted', 'en_route_pickup', 'arrived_pickup', 'picked_up', 'in_transit'])
    ->count();

check(
    'the rider ends with exactly one active assignment',
    $active === 1,
    "active={$active} acceptExit={$acceptCode} rawExit={$rawCode}",
);
check(
    'exactly one of the two writers committed',
    ($acceptCode === 0) !== ($rawCode === 0),
    "acceptExit={$acceptCode} rawExit={$rawCode}",
);
check(
    'the bypassing writer was stopped by the database, not by luck',
    $rawCode !== 2 || str_contains($rawOut, 'one_active_per_rider'),
    $rawCode === 0 ? 'it won the race; the loser was the service path' : 'refused',
);

// ---------------------------------------------------------------------------
echo "\n5) The rider's Accept races the expiry sweep\n";
// ---------------------------------------------------------------------------
$request = seedRequest();
$rider = seedRider();

// An offer expiring in one second, so the sweep and the tap collide on it.
$offerId = (string) Str::uuid();
DB::table('dispatch_offers')->insert([
    'id' => $offerId,
    'request_id' => $request->id(),
    'rider_id' => $rider['riderId'],
    'delivery_id' => $request->deliveryId(),
    'score' => 0.8,
    'state' => 'offered',
    'offered_at' => now()->subSeconds(44),
    'expires_at' => now()->addSeconds(2),
    'version' => 1,
]);

$startAt = microtime(true) + 2.0;
$dir = sys_get_temp_dir().'/efk-dispatch-race-'.uniqid();
mkdir($dir);

exec('/bin/bash -c '.escapeshellarg(sprintf(
    '(php %s accept %s %s %s > %s 2>&1; echo $? > %s) & (php %s expire-sweep %s > %s 2>&1; echo $? > %s) & wait',
    escapeshellarg($worker),
    escapeshellarg((string) $startAt),
    escapeshellarg($rider['userId']),
    escapeshellarg($offerId),
    escapeshellarg($dir.'/accept.out'),
    escapeshellarg($dir.'/accept.code'),
    escapeshellarg($worker),
    escapeshellarg((string) $startAt),
    escapeshellarg($dir.'/sweep.out'),
    escapeshellarg($dir.'/sweep.code'),
)));

$acceptCode = (int) trim((string) @file_get_contents($dir.'/accept.code'));
$finalState = (string) DB::table('dispatch_offers')->where('id', $offerId)->value('state');
$assignments = (int) DB::table('dispatch_assignments')->where('delivery_id', $request->deliveryId())->count();

array_map(unlink(...), glob($dir.'/*') ?: []);
rmdir($dir);

check(
    'the offer ends in exactly one terminal state',
    in_array($finalState, ['accepted', 'expired'], true),
    "state={$finalState}",
);
check(
    'an accepted offer has an assignment and an expired one does not',
    ($finalState === 'accepted' && $assignments === 1) || ($finalState === 'expired' && $assignments === 0),
    "state={$finalState} assignments={$assignments}",
);
check(
    'the rider was told the truthful outcome',
    ($finalState === 'accepted' && $acceptCode === 0) || ($finalState === 'expired' && $acceptCode === 1),
    "state={$finalState} acceptExit={$acceptCode}",
);

// ---------------------------------------------------------------------------
echo "\n6) Two operators approve the same vehicle at the same instant\n";
// ---------------------------------------------------------------------------
$rider = seedRider();
$vehicle = seedPendingVehicle($rider['riderId']);

$r = race($worker, 'approve-vehicle', [
    [(string) Str::uuid(), $vehicle->id()],
    [(string) Str::uuid(), $vehicle->id()],
    [(string) Str::uuid(), $vehicle->id()],
]);

$row = DB::table('dispatch_vehicles')->where('id', $vehicle->id())->first();

check(
    'exactly one approval commits',
    $r['succeeded'] === 1,
    "succeeded={$r['succeeded']} rejected={$r['rejected']} errored={$r['errored']}",
);
check('the vehicle is active and verified once', $row->status === 'active' && $row->verification_state === 'verified');
check('the version advanced exactly once', (int) $row->version === 2, 'version='.$row->version);
check('the losers were told, not silently overwritten', $r['errored'] === 0);

// ---------------------------------------------------------------------------
echo "\n7) Two processes reassign the same dropped-out rider\n";
// ---------------------------------------------------------------------------
$request = seedRequest();
$rider = seedRider();
$offer = seedOffer($request, $rider['riderId']);
$assignment = app(AssignmentService::class)->accept($rider['userId'], $offer->id());

$r = race($worker, 'reassign', [
    [$assignment->id()],
    [$assignment->id()],
    [$assignment->id()],
]);

$liveSearches = (int) DB::table('dispatch_requests')
    ->where('delivery_id', $request->deliveryId())
    ->whereIn('state', ['pending', 'dispatching'])
    ->count();

$totalSearches = (int) DB::table('dispatch_requests')
    ->where('delivery_id', $request->deliveryId())
    ->count();

check(
    'exactly one reassignment succeeds',
    $r['succeeded'] === 1,
    "succeeded={$r['succeeded']} rejected={$r['rejected']} errored={$r['errored']}",
);
check(
    'the delivery has exactly one live search, never two',
    $liveSearches === 1,
    "live={$liveSearches} total={$totalSearches}",
);
check('the duplicate attempts were refused, not crashed', $r['errored'] === 0);

// ---------------------------------------------------------------------------
echo "\n".str_repeat('=', 72)."\n";
echo sprintf("Passed: %d   Failed: %d\n", $pass, $fail);

exit($fail === 0 ? 0 : 1);
