<?php

declare(strict_types=1);

use EruoFood\Dispatch\Domain\Enum\DispatchFailureReason;
use EruoFood\Dispatch\Domain\Enum\DispatchState;
use EruoFood\Dispatch\Domain\Enum\RejectionReason;
use EruoFood\Dispatch\Domain\Enum\VehicleType;
use EruoFood\Dispatch\Domain\Exception\DispatchInvalidState;
use EruoFood\Dispatch\Domain\Request\DispatchAttempt;
use EruoFood\Dispatch\Domain\Request\DispatchRequest;

/**
 * M26 — one delivery's search for a rider.
 *
 * The properties under test are the ones that decide how long a customer waits
 * and whether they get one rider or two:
 *
 * - only one worker may claim a request;
 * - the search has a fixed deadline it cannot extend for itself;
 * - both budgets — attempts and time — end it, and it ends honestly;
 * - a terminal request stays terminal.
 */
function aRequest(
    ?DateTimeImmutable $now = null,
    int $maxAttempts = 5,
    int $timeBudgetSeconds = 600,
): DispatchRequest {
    return DispatchRequest::open(
        id: '11111111-1111-4111-8111-111111111111',
        deliveryId: '22222222-2222-4222-8222-222222222222',
        orderId: '33333333-3333-4333-8333-333333333333',
        vendorId: '44444444-4444-4444-8444-444444444444',
        pickupLat: 6.5244,
        pickupLng: 3.3792,
        dropoffLat: 6.4531,
        dropoffLng: 3.3958,
        now: $now ?? new DateTimeImmutable('2026-06-01 12:00:00'),
        maxAttempts: $maxAttempts,
        timeBudgetSeconds: $timeBudgetSeconds,
    );
}

it('opens pending, with the clock already running', function (): void {
    $now = new DateTimeImmutable('2026-06-01 12:00:00');
    $request = aRequest($now, timeBudgetSeconds: 600);

    expect($request->state())->toBe(DispatchState::Pending)
        ->and($request->attemptCount())->toBe(0)
        ->and($request->assignedRiderId())->toBeNull()
        ->and($request->expiresAt())->toEqual(new DateTimeImmutable('2026-06-01 12:10:00'));
});

it('defaults to the smallest vehicle, so a bike order is not refused a bike', function (): void {
    expect(aRequest()->requiredVehicleType())->toBe(VehicleType::Bike);
});

/**
 * The rule that stops two riders arriving at one restaurant.
 */
it('lets exactly one worker claim it', function (): void {
    $now = new DateTimeImmutable('2026-06-01 12:00:00');
    $request = aRequest($now);

    $request->claim($now);
    expect($request->state())->toBe(DispatchState::Dispatching);

    // A second worker, reading the same row, is refused.
    expect(fn () => $request->claim($now))->toThrow(DispatchInvalidState::class);
});

it('can be released back to the queue without burning an attempt', function (): void {
    $now = new DateTimeImmutable('2026-06-01 12:00:00');
    $request = aRequest($now);

    $request->claim($now);
    $request->release($now);

    // A worker shutting down cleanly hands the request back rather than
    // silently consuming one of the customer's five chances.
    expect($request->state())->toBe(DispatchState::Pending)
        ->and($request->attemptCount())->toBe(0);
});

it('stops attempting once the attempt budget is spent', function (): void {
    $now = new DateTimeImmutable('2026-06-01 12:00:00');
    $request = aRequest($now, maxAttempts: 2);

    expect($request->mayAttemptAgain($now))->toBeTrue();

    $request->recordAttempt($now);
    expect($request->mayAttemptAgain($now))->toBeTrue();

    $request->recordAttempt($now);
    expect($request->mayAttemptAgain($now))->toBeFalse();
});

/**
 * Attempts alone are not enough: a request that finds a candidate every round
 * would keep going for an hour while the customer waits.
 */
it('stops attempting once the time budget is spent, however few attempts were used', function (): void {
    $now = new DateTimeImmutable('2026-06-01 12:00:00');
    $request = aRequest($now, maxAttempts: 50, timeBudgetSeconds: 600);

    expect($request->mayAttemptAgain(new DateTimeImmutable('2026-06-01 12:09:59')))->toBeTrue()
        ->and($request->mayAttemptAgain(new DateTimeImmutable('2026-06-01 12:10:00')))->toBeFalse()
        ->and($request->hasExpired(new DateTimeImmutable('2026-06-01 12:10:01')))->toBeTrue();
});

it('does not let the deadline move when attempts are recorded', function (): void {
    $now = new DateTimeImmutable('2026-06-01 12:00:00');
    $request = aRequest($now, timeBudgetSeconds: 600);
    $deadline = $request->expiresAt();

    $request->claim($now);
    $request->recordAttempt(new DateTimeImmutable('2026-06-01 12:05:00'));
    $request->recordAttempt(new DateTimeImmutable('2026-06-01 12:08:00'));

    // A deadline that slides forward with activity is not a deadline.
    expect($request->expiresAt())->toEqual($deadline);
});

it('records who took it, and when, on assignment', function (): void {
    $now = new DateTimeImmutable('2026-06-01 12:03:00');
    $request = aRequest();
    $request->claim($now);

    $request->assign('55555555-5555-4555-8555-555555555555', $now);

    expect($request->state())->toBe(DispatchState::Assigned)
        ->and($request->assignedRiderId())->toBe('55555555-5555-4555-8555-555555555555')
        ->and($request->assignedAt())->toEqual($now)
        ->and($request->state()->isTerminal())->toBeTrue();
});

/**
 * A rider dropping out opens a *new* request rather than reopening this one, so
 * the record of what was already tried stays readable.
 */
it('refuses to be reassigned, failed or cancelled once it is terminal', function (): void {
    $now = new DateTimeImmutable('2026-06-01 12:03:00');
    $request = aRequest();
    $request->claim($now);
    $request->assign('55555555-5555-4555-8555-555555555555', $now);

    expect(fn () => $request->assign('66666666-6666-4666-8666-666666666666', $now))
        ->toThrow(DispatchInvalidState::class);

    expect(fn () => $request->fail(DispatchFailureReason::OfferDeclined, $now))
        ->toThrow(DispatchInvalidState::class);

    expect(fn () => $request->cancel($now))
        ->toThrow(DispatchInvalidState::class);
});

it('fails with a reason, never just "failed"', function (): void {
    $now = new DateTimeImmutable('2026-06-01 12:10:00');
    $request = aRequest();

    $request->fail(DispatchFailureReason::MaxAttemptsExhausted, $now);

    expect($request->state())->toBe(DispatchState::Failed)
        ->and($request->failureReason())->toBe(DispatchFailureReason::MaxAttemptsExhausted)
        ->and($request->failedAt())->toEqual($now)
        // The reasons that mean "stop and tell somebody" rather than "try again".
        ->and($request->failureReason()->isRetryable())->toBeFalse()
        ->and($request->failureReason()->warrantsAlert())->toBeTrue();
});

it('records a cancellation as its own reason', function (): void {
    $now = new DateTimeImmutable('2026-06-01 12:02:00');
    $request = aRequest();

    $request->cancel($now);

    expect($request->state())->toBe(DispatchState::Cancelled)
        ->and($request->failureReason())->toBe(DispatchFailureReason::Cancelled);
});

it('reports how long the customer has been waiting', function (): void {
    $request = aRequest(new DateTimeImmutable('2026-06-01 12:00:00'));

    expect($request->elapsedSeconds(new DateTimeImmutable('2026-06-01 12:03:20')))->toBe(200)
        // Never negative, however confused a clock is.
        ->and($request->elapsedSeconds(new DateTimeImmutable('2026-06-01 11:00:00')))->toBe(0);
});

/*
|------------------------------------------------------------------------------
| Attempts — the record of why a round went the way it did.
|------------------------------------------------------------------------------
*/

function anAttempt(array $breakdown = [], int $raw = 0, int $eligible = 0): DispatchAttempt
{
    return DispatchAttempt::record(
        id: '77777777-7777-4777-8777-777777777777',
        requestId: '11111111-1111-4111-8111-111111111111',
        attemptNumber: 1,
        searchRadiusMetres: 3_000,
        rawCandidateCount: $raw,
        eligibleCandidateCount: $eligible,
        rejectionBreakdown: $breakdown,
        startedAt: new DateTimeImmutable('2026-06-01 12:00:00'),
        completedAt: new DateTimeImmutable('2026-06-01 12:00:02'),
    );
}

it('names the reason that eliminated the most riders', function (): void {
    $attempt = anAttempt([
        RejectionReason::LocationStale->value => 9,
        RejectionReason::VehicleDocumentsExpired->value => 2,
    ], raw: 11);

    expect($attempt->dominantRejection())->toBe(RejectionReason::LocationStale)
        ->and($attempt->rejectedCount())->toBe(11);
});

it('discards reasons the enum does not know, so a typo cannot become a category', function (): void {
    $attempt = anAttempt([
        RejectionReason::LocationStale->value => 3,
        'rider_was_grumpy' => 40,
        RejectionReason::NoActiveVehicle->value => 0,
    ], raw: 3);

    expect($attempt->rejectionBreakdown())->toBe([RejectionReason::LocationStale->value => 3])
        // Without the filter the invented reason would win the count and
        // mislead whoever read the alert.
        ->and($attempt->dominantRejection())->toBe(RejectionReason::LocationStale);
});

it('explains an empty map differently from an ineligible fleet', function (): void {
    expect(anAttempt(raw: 0)->summary())
        ->toContain('No riders reported a position');

    expect(anAttempt([RejectionReason::VehicleNotVerified->value => 4], raw: 4)->summary())
        ->toContain('none eligible')
        ->toContain('vehicle not verified');

    expect(anAttempt([RejectionReason::LocationStale->value => 2], raw: 5, eligible: 3)->summary())
        ->toContain('3 of 5');
});

it('measures how long the round took', function (): void {
    expect(anAttempt()->durationMs())->toBe(2_000);
});
