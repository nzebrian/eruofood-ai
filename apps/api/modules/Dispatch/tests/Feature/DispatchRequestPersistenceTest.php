<?php

declare(strict_types=1);

use EruoFood\Dispatch\Domain\Enum\DispatchFailureReason;
use EruoFood\Dispatch\Domain\Enum\RejectionReason;
use EruoFood\Dispatch\Domain\Request\DispatchAttempt;
use EruoFood\Dispatch\Domain\Request\DispatchRequest;
use EruoFood\Dispatch\Domain\Request\DispatchRequestRepository;
use EruoFood\Shared\Domain\Exception\ConcurrencyConflict;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * M26 — dispatch requests and attempts, on a real database.
 *
 * The single guarantee this file exists for: **one live search per delivery.**
 * A retry, a queue redelivery or an operator clicking twice would otherwise
 * open two requests for one delivery, each offer a rider, and two riders would
 * arrive at one restaurant expecting one bag of food.
 *
 * The application avoids that. The partial unique index makes it impossible,
 * and that is the layer that still holds when a future refactor forgets.
 */
function requests(): DispatchRequestRepository
{
    return app(DispatchRequestRepository::class);
}

function openRequest(?string $deliveryId = null, int $maxAttempts = 5, int $budget = 600): DispatchRequest
{
    $request = DispatchRequest::open(
        id: requests()->nextIdentity(),
        deliveryId: $deliveryId ?? (string) Str::uuid(),
        orderId: (string) Str::uuid(),
        vendorId: (string) Str::uuid(),
        pickupLat: 6.5244,
        pickupLng: 3.3792,
        dropoffLat: 6.4531,
        dropoffLng: 3.3958,
        now: new DateTimeImmutable(),
        maxAttempts: $maxAttempts,
        timeBudgetSeconds: $budget,
    );

    requests()->save($request);

    return $request;
}

it('round-trips a request through the database unchanged', function (): void {
    $saved = openRequest();

    $loaded = requests()->find($saved->id());

    expect($loaded)->not->toBeNull()
        ->and($loaded->deliveryId())->toBe($saved->deliveryId())
        ->and($loaded->state())->toBe($saved->state())
        ->and($loaded->maxAttempts())->toBe($saved->maxAttempts())
        // Coordinates matter to seven places — a rounding loss here would move
        // the pickup point by tens of metres.
        ->and($loaded->pickupLat())->toBe($saved->pickupLat())
        ->and($loaded->dropoffLng())->toBe($saved->dropoffLng())
        ->and($loaded->expiresAt()->getTimestamp())->toBe($saved->expiresAt()->getTimestamp());
});

it('refuses a second live search for one delivery', function (): void {
    $deliveryId = (string) Str::uuid();
    openRequest($deliveryId);

    expect(fn () => openRequest($deliveryId))->toThrow(QueryException::class);
});

it('allows a fresh search once the previous one ended', function (): void {
    $deliveryId = (string) Str::uuid();
    $first = openRequest($deliveryId);

    $first->fail(DispatchFailureReason::MaxAttemptsExhausted, new DateTimeImmutable());
    requests()->save($first);

    // Reassignment opens a new request rather than reopening the old one, so
    // the record of what was already tried stays readable.
    $second = openRequest($deliveryId);

    expect($second->id())->not->toBe($first->id())
        ->and(requests()->liveForDelivery($deliveryId)?->id())->toBe($second->id());
});

it('reports no live search once a request is answered', function (): void {
    $deliveryId = (string) Str::uuid();
    $request = openRequest($deliveryId);
    $request->claim(new DateTimeImmutable());
    $request->assign((string) Str::uuid(), new DateTimeImmutable());
    requests()->save($request);

    expect(requests()->liveForDelivery($deliveryId))->toBeNull();
});

it('offers claimable requests oldest first', function (): void {
    $first = openRequest();
    $second = openRequest();

    // The second is taken by a worker and so is no longer claimable.
    $second->claim(new DateTimeImmutable());
    requests()->save($second);

    $claimable = requests()->claimable();

    expect($claimable)->toHaveCount(1)
        ->and($claimable[0]->id())->toBe($first->id());
});

it('finds live requests whose time budget has run out', function (): void {
    $expired = openRequest(budget: -60);   // opened already past its deadline
    $healthy = openRequest(budget: 600);

    $timedOut = requests()->timedOut(new DateTimeImmutable());

    expect(array_map(static fn ($r): string => $r->id(), $timedOut))
        ->toContain($expired->id())
        ->not->toContain($healthy->id());
});

/**
 * Two workers, both convinced they may attempt again.
 *
 * The version check catches the loser. This is a simulated race — real
 * multi-process proof comes from the concurrency script, because
 * `RefreshDatabase` wraps each test in a transaction and so can never
 * demonstrate locking.
 */
it('rejects a second worker writing against a stale version', function (): void {
    $request = openRequest();

    $workerA = requests()->find($request->id());
    $workerB = requests()->find($request->id());

    $workerA->claim(new DateTimeImmutable());
    requests()->save($workerA);

    $workerB->claim(new DateTimeImmutable());

    expect(fn () => requests()->save($workerB))->toThrow(ConcurrencyConflict::class);
});

it('refuses to store an assigned request with no rider', function (): void {
    $request = openRequest();

    // Reaching past the aggregate, the way a bad raw query would.
    expect(fn () => DB::table('dispatch_requests')
        ->where('id', $request->id())
        ->update(['state' => 'assigned']))
        ->toThrow(QueryException::class);
})->skip(
    fn (): bool => DB::connection()->getDriverName() !== 'pgsql',
    'CHECK constraints are declared for PostgreSQL.',
);

it('refuses to store a failure with no reason', function (): void {
    $request = openRequest();

    expect(fn () => DB::table('dispatch_requests')
        ->where('id', $request->id())
        ->update(['state' => 'failed']))
        ->toThrow(QueryException::class);
})->skip(
    fn (): bool => DB::connection()->getDriverName() !== 'pgsql',
    'CHECK constraints are declared for PostgreSQL.',
);

/*
|------------------------------------------------------------------------------
| Attempts
|------------------------------------------------------------------------------
*/

it('stores the breakdown that tells an outage from a paperwork backlog', function (): void {
    $request = openRequest();

    requests()->recordAttempt(DispatchAttempt::record(
        id: (string) Str::uuid(),
        requestId: $request->id(),
        attemptNumber: 1,
        searchRadiusMetres: 3_000,
        rawCandidateCount: 11,
        eligibleCandidateCount: 0,
        rejectionBreakdown: [
            RejectionReason::LocationStale->value => 9,
            RejectionReason::VehicleDocumentsExpired->value => 2,
        ],
        startedAt: new DateTimeImmutable('2026-06-01 12:00:00'),
        completedAt: new DateTimeImmutable('2026-06-01 12:00:01'),
        outcome: DispatchFailureReason::NoEligibleRiders,
    ));

    $attempts = requests()->attemptsFor($request->id());

    expect($attempts)->toHaveCount(1)
        ->and($attempts[0]->rawCandidateCount())->toBe(11)
        ->and($attempts[0]->eligibleCandidateCount())->toBe(0)
        ->and($attempts[0]->dominantRejection())->toBe(RejectionReason::LocationStale)
        ->and($attempts[0]->outcome())->toBe(DispatchFailureReason::NoEligibleRiders)
        ->and($attempts[0]->summary())->toContain('location stale');
});

it('returns attempts in the order they were tried', function (): void {
    $request = openRequest();

    foreach ([3, 1, 2] as $number) {
        requests()->recordAttempt(DispatchAttempt::record(
            id: (string) Str::uuid(),
            requestId: $request->id(),
            attemptNumber: $number,
            searchRadiusMetres: 1_000 * $number,
            rawCandidateCount: 0,
            eligibleCandidateCount: 0,
            rejectionBreakdown: [],
            startedAt: new DateTimeImmutable(),
            completedAt: new DateTimeImmutable(),
        ));
    }

    expect(array_map(
        static fn (DispatchAttempt $a): int => $a->attemptNumber(),
        requests()->attemptsFor($request->id()),
    ))->toBe([1, 2, 3]);
});

it('refuses to record the same attempt number twice for one request', function (): void {
    $request = openRequest();

    $attempt = fn (): DispatchAttempt => DispatchAttempt::record(
        id: (string) Str::uuid(),
        requestId: $request->id(),
        attemptNumber: 1,
        searchRadiusMetres: 3_000,
        rawCandidateCount: 0,
        eligibleCandidateCount: 0,
        rejectionBreakdown: [],
        startedAt: new DateTimeImmutable(),
        completedAt: new DateTimeImmutable(),
    );

    requests()->recordAttempt($attempt());

    // A duplicated round would double-count in every dispatch health metric.
    expect(fn () => requests()->recordAttempt($attempt()))->toThrow(QueryException::class);
});

it('will not let a recorded attempt be edited or deleted', function (): void {
    $request = openRequest();
    $id = (string) Str::uuid();

    requests()->recordAttempt(DispatchAttempt::record(
        id: $id,
        requestId: $request->id(),
        attemptNumber: 1,
        searchRadiusMetres: 3_000,
        rawCandidateCount: 4,
        eligibleCandidateCount: 0,
        rejectionBreakdown: [RejectionReason::NoActiveVehicle->value => 4],
        startedAt: new DateTimeImmutable(),
        completedAt: new DateTimeImmutable(),
    ));

    // A dispatch history that can be tidied up after the fact is not a history.
    expect(fn () => DB::table('dispatch_attempts')->where('id', $id)->update(['raw_candidate_count' => 99]))
        ->toThrow(QueryException::class, 'append-only');
})->skip(
    fn (): bool => DB::connection()->getDriverName() !== 'pgsql',
    'The append-only trigger is a PostgreSQL guarantee.',
);
