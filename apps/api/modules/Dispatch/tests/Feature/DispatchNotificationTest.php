<?php

declare(strict_types=1);

use EruoFood\Dispatch\Application\Service\AssignmentService;
use EruoFood\Dispatch\Application\Service\ReassignmentService;
use EruoFood\Dispatch\Application\Service\VehicleService;
use EruoFood\Dispatch\Domain\Enum\VehicleType;
use EruoFood\Notifications\Domain\Enum\NotificationCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * M26 — telling riders what happened, through M24's notification service.
 *
 * Two properties matter here, and the second is the one with teeth.
 *
 * **One notification system.** Everything goes through `NotificationService`,
 * which already owns preferences, quiet hours and channel selection. A second
 * one would mean a rider configuring their preferences twice and being
 * surprised by whichever context ignored them.
 *
 * **No coordinates in a notification, ever.** A notification fans out to
 * channels the platform does not control — a push provider, an SMS gateway, a
 * device notification tray, a lock screen. A rider's position is the most
 * sensitive data on this platform, and a customer's address is not far behind.
 * Neither belongs anywhere near a broadcast.
 */
function dispatchNotificationsFor(string $userId): array
{
    return DB::table('notifications_notifications')->where('user_id', $userId)->get()->all();
}

/**
 * A rider who can actually accept work.
 *
 * The position and the approved vehicle are both needed: the acceptance-time
 * eligibility re-check refuses a rider with no dispatchable vehicle, which is
 * exactly what it is there for.
 */
function notifiableRider(): array
{
    ['userId' => $userId, 'riderId' => $riderId] = dispatchRider();
    locate($riderId, $userId);
    approveVehicleFor($riderId, new DateTimeImmutable('+1 year'));

    return ['userId' => $userId, 'riderId' => $riderId];
}

it('tells a rider through the delivery category when they are assigned', function (): void {
    ['userId' => $userId, 'riderId' => $riderId] = notifiableRider();
    $request = liveRequest();
    $offer = offerTo($request, $riderId);

    app(AssignmentService::class)->accept($userId, $offer->id());

    $sent = dispatchNotificationsFor($userId);

    expect($sent)->not->toBeEmpty()
        ->and($sent[0]->category)->toBe(NotificationCategory::Delivery->value)
        ->and($sent[0]->template_key)->toBe('dispatch.delivery_assigned');
});

/**
 * The property with teeth.
 */
it('never puts a coordinate in a notification payload', function (): void {
    ['userId' => $userId, 'riderId' => $riderId] = notifiableRider();
    $request = liveRequest();
    $offer = offerTo($request, $riderId);

    $assignment = app(AssignmentService::class)->accept($userId, $offer->id());
    app(ReassignmentService::class)->reassign($assignment->id(), 'Rider went offline.');

    $sent = dispatchNotificationsFor($userId);
    expect($sent)->not->toBeEmpty();

    foreach ($sent as $notification) {
        $payload = (string) $notification->data;

        // The rider's own position, the pickup and the dropoff — none of them
        // may travel on a channel the platform does not control.
        expect($payload)->not->toContain('latitude')
            ->not->toContain('longitude')
            ->not->toContain('6.5244')
            ->not->toContain('3.3792')
            ->not->toContain('6.4531')
            ->not->toContain('3.3958')
            ->not->toContain('address');
    }
});

it('tells a rider when their vehicle is approved and when it is rejected', function (): void {
    ['userId' => $userId] = notifiableRider();

    $vehicle = app(VehicleService::class)->register($userId, VehicleType::Bike);
    app(VehicleService::class)->approve(anOperator(), $vehicle->id());

    $keys = array_map(static fn ($n): string => (string) $n->template_key, dispatchNotificationsFor($userId));

    expect($keys)->toContain('dispatch.vehicle_approved');

    $second = app(VehicleService::class)->register($userId, VehicleType::Car, registrationNumber: 'LAG-5');
    app(VehicleService::class)->reject(anOperator(), $second->id(), 'Papers unreadable.');

    // A rider waiting on a verification needs the rejection at least as much as
    // the approval — it is the one that tells them to do something.
    expect(array_map(static fn ($n): string => (string) $n->template_key, dispatchNotificationsFor($userId)))
        ->toContain('dispatch.vehicle_rejected');
});

it('tells a rider their offer expired, quietly', function (): void {
    ['userId' => $userId, 'riderId' => $riderId] = notifiableRider();
    $request = liveRequest();

    $offer = EruoFood\Dispatch\Domain\Offer\RiderOffer::make(
        id: offerRepo()->nextIdentity(),
        requestId: $request->id(),
        riderId: $riderId,
        deliveryId: $request->deliveryId(),
        now: new DateTimeImmutable('-5 minutes'),
        ttlSeconds: 45,
    );
    offerRepo()->save($offer);

    app(EruoFood\Dispatch\Application\Service\OfferExpiryService::class)->sweepOffers();

    $expired = array_values(array_filter(
        dispatchNotificationsFor($userId),
        static fn ($n): bool => $n->template_key === 'dispatch.offer_expired',
    ));

    // In-app only and low priority: a push saying "you missed one" at 2am helps
    // nobody, and a rider who had no signal is not at fault.
    expect($expired)->toHaveCount(1)
        ->and($expired[0]->channel)->toBe('in_app');
});

/**
 * A notification is a side effect of a decision that has already been
 * committed. Rolling that decision back because a push failed would be worse
 * than the missed push.
 */
it('does not fail an acceptance when the notifier is broken', function (): void {
    ['userId' => $userId, 'riderId' => $riderId] = notifiableRider();
    $request = liveRequest();
    $offer = offerTo($request, $riderId);

    app()->bind(
        EruoFood\Notifications\Application\Service\NotificationService::class,
        fn () => throw new RuntimeException('notifier is down'),
    );

    $assignment = app(AssignmentService::class)->accept($userId, $offer->id());

    expect($assignment->riderId())->toBe($riderId)
        ->and(DB::table('dispatch_assignments')->where('id', $assignment->id())->exists())->toBeTrue();
});
