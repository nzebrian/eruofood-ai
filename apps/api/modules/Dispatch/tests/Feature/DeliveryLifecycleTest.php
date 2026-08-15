<?php

declare(strict_types=1);

use EruoFood\Dispatch\Application\Service\AssignmentService;
use EruoFood\Dispatch\Application\Service\DeliveryProgressService;
use EruoFood\Dispatch\Domain\Assignment\AssignmentRepository;
use EruoFood\Dispatch\Domain\Enum\AssignmentState;
use EruoFood\Dispatch\Domain\Exception\DispatchInvalidState;
use EruoFood\Dispatch\Domain\Exception\DispatchNotAuthorized;
use EruoFood\Marketplace\Domain\Enum\DeliveryStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * M26 — the delivery lifecycle, end to end, across the context boundary.
 *
 * The approved state machine:
 *
 *     OFFERED → ACCEPTED → EN_ROUTE_PICKUP → ARRIVED_PICKUP
 *             → PICKED_UP → IN_TRANSIT → DELIVERED
 *
 * Two things are being held at once here, and they are easy to confuse:
 *
 * **The journey is one process with two records.** Marketplace's `Delivery` is
 * the operational one (M26 decision 1); Dispatch's assignment mirrors it. Every
 * test below asserts *both*, because a design where they can disagree is a
 * design where a customer is told one thing and an operator sees another.
 *
 * **Marketplace leads.** `DeliveryProgressService` advances the delivery first
 * and mirrors second, so no code path exists that moves the assignment without
 * the delivery having agreed.
 */
function progressRider(): array
{
    return dispatchRider();
}

/** Walk a delivery all the way to a rider holding it. */
function acceptedAssignment(): array
{
    ['userId' => $userId, 'riderId' => $riderId] = dispatchRider();
    $request = liveRequest();
    $offer = offerTo($request, $riderId);
    $assignment = app(AssignmentService::class)->accept($userId, $offer->id());

    return [
        'userId' => $userId,
        'riderId' => $riderId,
        'request' => $request,
        'assignment' => $assignment,
        'deliveryId' => $request->deliveryId(),
    ];
}

function deliveryStatus(string $deliveryId): string
{
    return (string) DB::table('marketplace_deliveries')->where('id', $deliveryId)->value('status');
}

it('records acceptance on the delivery, not just on the assignment', function (): void {
    ['deliveryId' => $deliveryId, 'riderId' => $riderId] = acceptedAssignment();

    expect(deliveryStatus($deliveryId))->toBe(DeliveryStatus::Accepted->value)
        ->and(DB::table('marketplace_deliveries')->where('id', $deliveryId)->value('rider_id'))
        ->toBe($riderId);
});

it('walks the whole journey, keeping both records in step', function (): void {
    ['userId' => $userId, 'assignment' => $assignment, 'deliveryId' => $deliveryId] = acceptedAssignment();

    // Pairs rather than a keyed map: enum cases cannot be array keys.
    $journey = [
        [AssignmentState::EnRoutePickup, DeliveryStatus::EnRoutePickup],
        [AssignmentState::ArrivedPickup, DeliveryStatus::ArrivedPickup],
        [AssignmentState::PickedUp, DeliveryStatus::PickedUp],
        [AssignmentState::InTransit, DeliveryStatus::InTransit],
        [AssignmentState::Delivered, DeliveryStatus::Delivered],
    ];

    foreach ($journey as [$next, $expectedDeliveryStatus]) {
        $updated = app(DeliveryProgressService::class)->advance($userId, $assignment->id(), $next);

        expect($updated->state())->toBe($next)
            // Both records, every step. If these can drift, a customer is told
            // one thing and an operator sees another.
            ->and(deliveryStatus($deliveryId))->toBe($expectedDeliveryStatus->value);
    }
});

it('stamps the delivered time when the journey ends', function (): void {
    ['userId' => $userId, 'assignment' => $assignment, 'deliveryId' => $deliveryId] = acceptedAssignment();

    foreach ([AssignmentState::EnRoutePickup, AssignmentState::ArrivedPickup, AssignmentState::PickedUp, AssignmentState::InTransit, AssignmentState::Delivered] as $next) {
        app(DeliveryProgressService::class)->advance($userId, $assignment->id(), $next);
    }

    expect(DB::table('marketplace_deliveries')->where('id', $deliveryId)->value('delivered_at'))
        ->not->toBeNull();
});

it('treats DELIVERED as terminal', function (): void {
    ['userId' => $userId, 'assignment' => $assignment] = acceptedAssignment();

    foreach ([AssignmentState::EnRoutePickup, AssignmentState::ArrivedPickup, AssignmentState::PickedUp, AssignmentState::InTransit, AssignmentState::Delivered] as $next) {
        app(DeliveryProgressService::class)->advance($userId, $assignment->id(), $next);
    }

    // Nothing follows a delivered order. Letting one move again would let a
    // mistake or a bad actor rewrite a customer's completed delivery.
    foreach (AssignmentState::cases() as $next) {
        expect(fn () => app(DeliveryProgressService::class)->advance($userId, $assignment->id(), $next))
            ->toThrow(DispatchInvalidState::class);
    }
});

it('refuses every illegal transition, in both directions', function (): void {
    ['userId' => $userId, 'assignment' => $assignment] = acceptedAssignment();

    // Skipping ahead.
    foreach ([AssignmentState::ArrivedPickup, AssignmentState::PickedUp, AssignmentState::InTransit, AssignmentState::Delivered] as $skip) {
        expect(fn () => app(DeliveryProgressService::class)->advance($userId, $assignment->id(), $skip))
            ->toThrow(DispatchInvalidState::class);
    }

    app(DeliveryProgressService::class)->advance($userId, $assignment->id(), AssignmentState::EnRoutePickup);
    app(DeliveryProgressService::class)->advance($userId, $assignment->id(), AssignmentState::ArrivedPickup);

    // Going backwards.
    expect(fn () => app(DeliveryProgressService::class)->advance($userId, $assignment->id(), AssignmentState::EnRoutePickup))
        ->toThrow(DispatchInvalidState::class);
});

/**
 * A rider must not be able to reassign or cancel their own work by dressing it
 * up as a state change.
 */
it('refuses to let a rider drive the states that are not theirs to drive', function (): void {
    ['userId' => $userId, 'assignment' => $assignment] = acceptedAssignment();

    foreach ([AssignmentState::Accepted, AssignmentState::Cancelled, AssignmentState::ReassignmentRequired] as $notTheirs) {
        expect(fn () => app(DeliveryProgressService::class)->advance($userId, $assignment->id(), $notTheirs))
            ->toThrow(DispatchInvalidState::class);
    }
});

it('refuses to let one rider advance another rider\'s delivery', function (): void {
    ['assignment' => $assignment] = acceptedAssignment();
    ['userId' => $stranger] = dispatchRider();

    expect(fn () => app(DeliveryProgressService::class)->advance($stranger, $assignment->id(), AssignmentState::EnRoutePickup))
        ->toThrow(DispatchNotAuthorized::class);
});

it('leaves both records untouched when a transition is refused', function (): void {
    ['userId' => $userId, 'assignment' => $assignment, 'deliveryId' => $deliveryId] = acceptedAssignment();

    try {
        app(DeliveryProgressService::class)->advance($userId, $assignment->id(), AssignmentState::Delivered);
    } catch (DispatchInvalidState) {
        // expected
    }

    expect(deliveryStatus($deliveryId))->toBe(DeliveryStatus::Accepted->value)
        ->and(app(AssignmentRepository::class)->find($assignment->id())->state())
        ->toBe(AssignmentState::Accepted);
});

it('releases the delivery back to unassigned when a rider is reassigned away', function (): void {
    ['assignment' => $assignment, 'deliveryId' => $deliveryId] = acceptedAssignment();

    app(EruoFood\Dispatch\Application\Service\ReassignmentService::class)
        ->reassign($assignment->id(), 'Rider went offline.');

    // The delivery stops claiming a rider who is not coming. Leaving the old
    // rider on it is how a customer gets told somebody is on the way for
    // another ten minutes.
    expect(deliveryStatus($deliveryId))->toBe(DeliveryStatus::Unassigned->value)
        ->and(DB::table('marketplace_deliveries')->where('id', $deliveryId)->value('rider_id'))
        ->toBeNull();
});

it('offers the rider only the next legal step', function (): void {
    ['assignment' => $assignment] = acceptedAssignment();

    expect(app(DeliveryProgressService::class)->nextStatesFor($assignment))
        ->toBe([AssignmentState::EnRoutePickup]);
});

/**
 * The rider-drivable whitelist, asserted on its own.
 *
 * The state machine *does* allow `Accepted → Cancelled` and
 * `Accepted → ReassignmentRequired` — those are legal transitions, just not a
 * rider's to make. So the whitelist is a separate protection from the
 * transition table, and testing it through an HTTP status cannot tell the two
 * apart. This does.
 */
it('never offers a rider a state that is not theirs to drive', function (): void {
    ['assignment' => $assignment] = acceptedAssignment();

    $allowedByTheMachine = $assignment->state()->allowedNext();
    $offeredToTheRider = app(DeliveryProgressService::class)->nextStatesFor($assignment);

    // The machine permits three; the rider may drive one.
    expect($allowedByTheMachine)->toContain(AssignmentState::Cancelled)
        ->toContain(AssignmentState::ReassignmentRequired)
        ->and($offeredToTheRider)->not->toContain(AssignmentState::Cancelled)
        ->not->toContain(AssignmentState::ReassignmentRequired);
});

/*
|------------------------------------------------------------------------------
| The legacy names, which M26 kept rather than rewrote.
|------------------------------------------------------------------------------
*/

it('keeps the pre-M26 status names working', function (): void {
    // Existing rows hold `assigned` and `en_route`. A migration that rewrote
    // live delivery rows to tidy an enum would be changing operational records
    // for cosmetics.
    expect(DeliveryStatus::Assigned->canonical())->toBe(DeliveryStatus::Accepted)
        ->and(DeliveryStatus::EnRoute->canonical())->toBe(DeliveryStatus::InTransit)
        ->and(DeliveryStatus::Assigned->isLegacyAlias())->toBeTrue()
        ->and(DeliveryStatus::Accepted->isLegacyAlias())->toBeFalse();
});

it('lets a delivery stored under a legacy name still advance', function (): void {
    ['deliveryId' => $deliveryId] = acceptedAssignment();

    // As an older row would have been written.
    DB::table('marketplace_deliveries')->where('id', $deliveryId)->update(['status' => 'assigned']);

    expect(app(EruoFood\Dispatch\Application\Port\DeliveryLifecycle::class)
        ->advance($deliveryId, DeliveryStatus::EnRoutePickup->value))->toBeTrue()
        ->and(deliveryStatus($deliveryId))->toBe(DeliveryStatus::EnRoutePickup->value);
});

it('does not change what the pre-M26 vendor assign endpoint returns', function (): void {
    // A shipped endpoint whose clients read `data.status`. Quietly changing it
    // to tidy an enum would break them for no benefit anybody asked for.
    $delivery = EruoFood\Marketplace\Domain\Delivery\Delivery::create(
        id: (string) Illuminate\Support\Str::uuid(),
        orderId: (string) Illuminate\Support\Str::uuid(),
        vendorId: (string) Illuminate\Support\Str::uuid(),
        fee: new EruoFood\Shared\Domain\ValueObject\Money(50_000, 'NGN'),
        zoneName: null,
        pickup: null,
        dropoff: null,
        now: new DateTimeImmutable(),
    );

    $delivery->assignRider('rider-1', new DateTimeImmutable());

    expect($delivery->status())->toBe(DeliveryStatus::Assigned)
        ->and($delivery->status()->value)->toBe('assigned');
});
