<?php

declare(strict_types=1);

use EruoFood\Dispatch\Application\Service\AssignmentService;
use EruoFood\Dispatch\Application\Service\DeliveryProgressService;
use EruoFood\Dispatch\Application\Service\OfferExpiryService;
use EruoFood\Dispatch\Application\Service\ReassignmentService;
use EruoFood\Dispatch\Domain\Assignment\AssignmentRepository;
use EruoFood\Dispatch\Domain\Enum\AssignmentState;
use EruoFood\Dispatch\Domain\Enum\DispatchFailureReason;
use EruoFood\Dispatch\Domain\Enum\DispatchState;
use EruoFood\Dispatch\Domain\Enum\OfferState;
use EruoFood\Dispatch\Domain\Exception\DispatchInvalidState;
use EruoFood\Dispatch\Domain\Offer\OfferRepository;
use EruoFood\Dispatch\Domain\Offer\RiderOffer;
use EruoFood\Dispatch\Domain\Request\DispatchRequest;
use EruoFood\Dispatch\Domain\Request\DispatchRequestRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * M26 — nobody answered, and somebody dropped out.
 *
 * The two ways a dispatch quietly goes wrong if these are missing:
 *
 * **An offer nobody answered** leaves a rider apparently holding a job for
 * ever, blocked from other work by their own live offer, while the customer
 * waits on a request that is not looking for anybody.
 *
 * **A rider who drops out** leaves a delivery assigned to somebody who is not
 * coming. The customer has already been told a rider is on the way, which makes
 * this worse than never having found one.
 *
 * The sweep works from stored deadlines rather than timers, so it recovers from
 * an interrupted worker — that is why these tests move the clock rather than
 * waiting.
 */
function expiry(): OfferExpiryService
{
    return app(OfferExpiryService::class);
}

function reassignment(): ReassignmentService
{
    return app(ReassignmentService::class);
}

/** @return array{userId: string, riderId: string} */
function sweepRider(): array
{
    $userId = (string) Str::uuid();
    $riderId = (string) Str::uuid();

    DB::table('marketplace_riders')->insert([
        'id' => $riderId,
        'user_id' => $userId,
        'name' => 'Rider '.substr($riderId, 0, 4),
        'phone' => '+234800'.random_int(1_000_000, 9_999_999),
        'vehicle_type' => 'motorbike',
        'status' => 'online',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return ['userId' => $userId, 'riderId' => $riderId];
}

function sweepRequest(int $budgetSeconds = 600, ?DateTimeImmutable $openedAt = null): DispatchRequest
{
    $deliveryId = (string) Str::uuid();
    seedDelivery($deliveryId);

    $request = DispatchRequest::open(
        id: app(DispatchRequestRepository::class)->nextIdentity(),
        deliveryId: $deliveryId,
        orderId: (string) Str::uuid(),
        vendorId: (string) Str::uuid(),
        pickupLat: 6.5244,
        pickupLng: 3.3792,
        dropoffLat: 6.4531,
        dropoffLng: 3.3958,
        now: $openedAt ?? new DateTimeImmutable(),
        maxAttempts: 5,
        timeBudgetSeconds: $budgetSeconds,
    );

    app(DispatchRequestRepository::class)->save($request);

    return $request;
}

function staleOffer(DispatchRequest $request, string $riderId, string $offeredAt = '-5 minutes'): RiderOffer
{
    $offer = RiderOffer::make(
        id: app(OfferRepository::class)->nextIdentity(),
        requestId: $request->id(),
        riderId: $riderId,
        deliveryId: $request->deliveryId(),
        now: new DateTimeImmutable($offeredAt),
        ttlSeconds: 45,
    );

    app(OfferRepository::class)->save($offer);

    return $offer;
}

it('expires an offer nobody answered', function (): void {
    ['riderId' => $riderId] = sweepRider();
    $offer = staleOffer(sweepRequest(), $riderId);

    expect(expiry()->sweepOffers())->toBe(1);

    expect(app(OfferRepository::class)->find($offer->id())->state())->toBe(OfferState::Expired)
        // And the rider is free again, rather than blocked by their own dead
        // offer from every other job on the platform.
        ->and(app(OfferRepository::class)->liveForRider($riderId))->toBeNull();
});

it('leaves an offer that still has time on it alone', function (): void {
    ['riderId' => $riderId] = sweepRider();
    $offer = staleOffer(sweepRequest(), $riderId, offeredAt: 'now');

    expect(expiry()->sweepOffers())->toBe(0)
        ->and(app(OfferRepository::class)->find($offer->id())->state())->toBe(OfferState::Offered);
});

/**
 * A rider tapping Accept at the same instant the sweep runs. Losing that race
 * is a success, not an error.
 */
it('skips an offer the rider answered between the scan and the lock', function (): void {
    ['userId' => $userId, 'riderId' => $riderId] = sweepRider();
    $request = sweepRequest();

    // Still live when offered, then answered.
    $offer = staleOffer($request, $riderId, offeredAt: 'now');
    app(AssignmentService::class)->accept($userId, $offer->id());

    // Now the deadline passes and the sweep runs.
    expect(expiry()->sweepOffers())->toBe(0)
        ->and(app(OfferRepository::class)->find($offer->id())->state())->toBe(OfferState::Accepted);
});

it('does not re-expire an offer that was already declined', function (): void {
    ['userId' => $userId, 'riderId' => $riderId] = sweepRider();
    $offer = staleOffer(sweepRequest(), $riderId, offeredAt: 'now');

    app(AssignmentService::class)->decline($userId, $offer->id(), 'Busy.');

    expect(expiry()->sweepOffers())->toBe(0)
        ->and(app(OfferRepository::class)->find($offer->id())->state())->toBe(OfferState::Declined);
});

/*
|------------------------------------------------------------------------------
| Time budget
|------------------------------------------------------------------------------
*/

it('fails a search whose time budget has run out, with a reason', function (): void {
    $request = sweepRequest(budgetSeconds: 600, openedAt: new DateTimeImmutable('-20 minutes'));

    expect(expiry()->sweepTimedOutRequests())->toBe(1);

    $loaded = app(DispatchRequestRepository::class)->find($request->id());

    // After ten minutes a customer deserves to be told nobody could be found,
    // not to keep waiting while the engine cycles.
    expect($loaded->state())->toBe(DispatchState::Failed)
        ->and($loaded->failureReason())->toBe(DispatchFailureReason::TimeBudgetExhausted)
        ->and($loaded->failureReason()->warrantsAlert())->toBeTrue();
});

it('withdraws offers still on riders\' screens when the search is given up on', function (): void {
    ['riderId' => $riderId] = sweepRider();
    $request = sweepRequest(budgetSeconds: 600, openedAt: new DateTimeImmutable('-20 minutes'));
    $offer = staleOffer($request, $riderId, offeredAt: 'now');

    expiry()->sweepTimedOutRequests();

    expect(app(OfferRepository::class)->find($offer->id())->state())->toBe(OfferState::Cancelled);
});

it('leaves a search alone if a rider accepted before the sweep reached it', function (): void {
    ['userId' => $userId, 'riderId' => $riderId] = sweepRider();
    $request = sweepRequest(budgetSeconds: 600, openedAt: new DateTimeImmutable('-20 minutes'));
    $offer = staleOffer($request, $riderId, offeredAt: 'now');

    app(AssignmentService::class)->accept($userId, $offer->id());

    // The customer has a rider. There is nothing to fail.
    expect(expiry()->sweepTimedOutRequests())->toBe(0)
        ->and(app(DispatchRequestRepository::class)->find($request->id())->state())
        ->toBe(DispatchState::Assigned);
});

it('reports what it did through the console command', function (): void {
    ['riderId' => $riderId] = sweepRider();
    staleOffer(sweepRequest(), $riderId);

    $this->artisan('dispatch:expire-offers')
        ->expectsOutputToContain('Expired 1 offer')
        ->assertExitCode(0);
});

/*
|------------------------------------------------------------------------------
| Reassignment
|------------------------------------------------------------------------------
*/

it('releases the rider and opens a fresh search when one drops out', function (): void {
    ['userId' => $userId, 'riderId' => $riderId] = sweepRider();
    $request = sweepRequest();
    $offer = staleOffer($request, $riderId, offeredAt: 'now');
    $assignment = app(AssignmentService::class)->accept($userId, $offer->id());

    $replacement = reassignment()->reassign($assignment->id(), 'Rider went offline.');

    expect($replacement)->not->toBeNull()
        ->and($replacement->deliveryId())->toBe($request->deliveryId())
        ->and($replacement->id())->not->toBe($request->id())
        // The rider is free, and the old assignment is kept as the record that
        // somebody was assigned and did not finish.
        ->and(app(AssignmentRepository::class)->activeForRider($riderId))->toBeNull()
        ->and(app(AssignmentRepository::class)->find($assignment->id())->state())
        ->toBe(AssignmentState::ReassignmentRequired);
});

/**
 * The original search stays finished. Reopening it would erase what was already
 * tried — and would break "one live search per delivery".
 */
it('opens a new search rather than reopening the old one', function (): void {
    ['userId' => $userId, 'riderId' => $riderId] = sweepRider();
    $request = sweepRequest();
    $assignment = app(AssignmentService::class)->accept(
        $userId,
        staleOffer($request, $riderId, offeredAt: 'now')->id(),
    );

    $replacement = reassignment()->reassign($assignment->id(), 'Bike broke down.');

    expect(app(DispatchRequestRepository::class)->find($request->id())->state())
        ->toBe(DispatchState::Assigned)
        ->and(app(DispatchRequestRepository::class)->liveForDelivery($request->deliveryId())?->id())
        ->toBe($replacement->id())
        // Two searches for one delivery, one finished and one live — which the
        // partial unique index permits precisely because the first is closed.
        ->and(DB::table('dispatch_requests')->where('delivery_id', $request->deliveryId())->count())
        ->toBe(2);
});

/**
 * The customer has already been waiting; a rider dropping out at minute nine
 * must not buy the platform another ten minutes of their patience.
 */
it('carries over what is left of the deadline rather than granting a fresh one', function (): void {
    ['userId' => $userId, 'riderId' => $riderId] = sweepRider();
    $request = sweepRequest(budgetSeconds: 600, openedAt: new DateTimeImmutable('-7 minutes'));
    $assignment = app(AssignmentService::class)->accept(
        $userId,
        staleOffer($request, $riderId, offeredAt: 'now')->id(),
    );

    $replacement = reassignment()->reassign($assignment->id(), 'Rider unreachable.');

    // About three minutes left of the original ten, not a new ten.
    $remaining = $replacement->expiresAt()->getTimestamp() - time();

    expect($remaining)->toBeLessThan(200)
        ->and($remaining)->toBeGreaterThan(100)
        ->and($replacement->expiresAt()->getTimestamp())
        ->toBeLessThanOrEqual($request->expiresAt()->getTimestamp() + 1);
});

it('opens no replacement search when there is no time left to find anybody', function (): void {
    ['userId' => $userId, 'riderId' => $riderId] = sweepRider();
    // Seconds expressed absolutely: PHP reads '-9 minutes 50 seconds' as minus
    // nine minutes *plus* fifty seconds, which is not what this test means.
    $request = sweepRequest(budgetSeconds: 600, openedAt: new DateTimeImmutable('-590 seconds'));
    $assignment = app(AssignmentService::class)->accept(
        $userId,
        staleOffer($request, $riderId, offeredAt: 'now')->id(),
    );

    // A search that would fail in ten seconds wastes the pool's attention and
    // delays the honest answer, which is that this one needs a human.
    expect(reassignment()->reassign($assignment->id(), 'Rider unreachable.'))->toBeNull()
        ->and(app(AssignmentRepository::class)->find($assignment->id())->state())
        ->toBe(AssignmentState::ReassignmentRequired);
});

it('refuses to reassign once the rider is carrying the food', function (): void {
    ['userId' => $userId, 'riderId' => $riderId] = sweepRider();
    $request = sweepRequest();
    $assignment = app(AssignmentService::class)->accept(
        $userId,
        staleOffer($request, $riderId, offeredAt: 'now')->id(),
    );

    foreach ([AssignmentState::EnRoutePickup, AssignmentState::ArrivedPickup, AssignmentState::PickedUp] as $next) {
        app(DeliveryProgressService::class)->advance($userId, $assignment->id(), $next);
    }

    // Past pickup this is an operational incident with a meal in somebody's
    // bag, not a dispatch decision — and the refusal surfaces rather than being
    // quietly worked around.
    expect(fn () => reassignment()->reassign($assignment->id(), 'Rider unreachable.'))
        ->toThrow(DispatchInvalidState::class);
});

it('lets the replacement rider accept the delivery the first one dropped', function (): void {
    ['userId' => $firstUser, 'riderId' => $first] = sweepRider();
    ['userId' => $secondUser, 'riderId' => $second] = sweepRider();

    $request = sweepRequest();
    $assignment = app(AssignmentService::class)->accept(
        $firstUser,
        staleOffer($request, $first, offeredAt: 'now')->id(),
    );

    $replacement = reassignment()->reassign($assignment->id(), 'Rider went offline.');

    $newAssignment = app(AssignmentService::class)->accept(
        $secondUser,
        staleOffer($replacement, $second, offeredAt: 'now')->id(),
    );

    // The delivery's exclusivity index permits this precisely because the first
    // assignment is terminal.
    expect($newAssignment->riderId())->toBe($second)
        ->and(app(AssignmentRepository::class)->activeForDelivery($request->deliveryId())?->riderId())
        ->toBe($second);
});
