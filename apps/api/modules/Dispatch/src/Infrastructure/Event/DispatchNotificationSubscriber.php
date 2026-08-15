<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Infrastructure\Event;

use EruoFood\Dispatch\Application\Port\RiderDirectory;
use EruoFood\Dispatch\Domain\Event\DeliveryAssigned;
use EruoFood\Dispatch\Domain\Event\OfferExpired;
use EruoFood\Dispatch\Domain\Event\OfferMade;
use EruoFood\Dispatch\Domain\Event\ReassignmentRequired;
use EruoFood\Dispatch\Domain\Event\VehicleVerificationDecided;
use EruoFood\Notifications\Application\Service\NotificationService;
use EruoFood\Notifications\Domain\Enum\NotificationCategory;
use EruoFood\Notifications\Domain\Enum\NotificationChannel;
use EruoFood\Notifications\Domain\Enum\Priority;
use Throwable;

/**
 * Telling riders what just happened, through M24's notification service.
 *
 * No second notification system, no direct push calls. Everything goes through
 * {@see NotificationService}, which already owns preferences, quiet hours,
 * channel selection and delivery — all of which a rider has configured once and
 * should not have to configure again per context.
 *
 * ## What is deliberately not in these payloads
 *
 * **No coordinates.** Not the rider's, not the customer's. A notification fans
 * out to channels the platform does not control — a push provider, an SMS
 * gateway, a device notification tray — and a rider's position is the most
 * sensitive data in this platform. Anything that needs a location reads it
 * through the service that authorises such a read.
 *
 * **No customer address.** The rider gets it from the delivery once it is
 * theirs, behind authorisation, not in a broadcast telling them a job exists.
 *
 * ## Failures here never fail the operation
 *
 * A notification is a side effect of a decision that has already been committed.
 * If the notifier is down, a rider not getting a push is bad; a rider's
 * acceptance being rolled back because a push failed is worse. Every handler
 * swallows its own errors for that reason, and only for that reason.
 */
final readonly class DispatchNotificationSubscriber
{
    public function __construct(
        private NotificationService $notifications,
        private RiderDirectory $riders,
    ) {
    }

    /** A delivery is on a rider's screen and the clock is running. */
    public function onOfferMade(OfferMade $event): void
    {
        $this->tellRider(
            $event->riderId,
            'dispatch.offer_made',
            [
                'offer_id' => $event->offerId,
                'delivery_id' => $event->deliveryId,
                // The instant, not the remaining seconds: a notification that
                // is slow to deliver would otherwise arrive claiming more time
                // than the rider actually has.
                'expires_at' => $event->expiresAt,
            ],
            // Push only, and high priority. The rider has forty-five seconds;
            // an email about it is worse than useless.
            [NotificationChannel::Push],
            Priority::High,
            $event->offerId,
        );
    }

    /** The rider took it. */
    public function onDeliveryAssigned(DeliveryAssigned $event): void
    {
        $this->tellRider(
            $event->riderId,
            'dispatch.delivery_assigned',
            [
                'assignment_id' => $event->assignmentId,
                'delivery_id' => $event->deliveryId,
            ],
            [NotificationChannel::Push, NotificationChannel::InApp],
            Priority::High,
            $event->assignmentId,
        );
    }

    /** Nobody answered in time. */
    public function onOfferExpired(OfferExpired $event): void
    {
        $this->tellRider(
            $event->riderId,
            'dispatch.offer_expired',
            ['offer_id' => $event->offerId],
            // In-app only. A push saying "you missed one" at 2am helps nobody,
            // and a rider who had no signal is not at fault.
            [NotificationChannel::InApp],
            Priority::Low,
            $event->offerId,
        );
    }

    /** The rider is off this delivery and somebody else will take it. */
    public function onReassignmentRequired(ReassignmentRequired $event): void
    {
        $this->tellRider(
            $event->riderId,
            'dispatch.reassignment_required',
            [
                'assignment_id' => $event->assignmentId,
                'delivery_id' => $event->deliveryId,
                'reason' => $event->reason,
            ],
            [NotificationChannel::Push, NotificationChannel::InApp],
            Priority::High,
            $event->assignmentId,
        );
    }

    /**
     * An operator decided on a vehicle.
     *
     * The moment a rider's ability to earn changes, so the moment they should
     * be told. Both outcomes are sent — a rider waiting on a verification needs
     * the rejection at least as much as the approval.
     */
    public function onVehicleVerificationDecided(VehicleVerificationDecided $event): void
    {
        $this->tellRider(
            $event->riderId,
            $event->approved ? 'dispatch.vehicle_approved' : 'dispatch.vehicle_rejected',
            [
                'vehicle_id' => $event->vehicleId,
                'reason' => $event->reason,
            ],
            [NotificationChannel::Push, NotificationChannel::InApp, NotificationChannel::Email],
            Priority::Normal,
            $event->vehicleId,
        );
    }

    /**
     * @param array<string, mixed> $data
     * @param list<NotificationChannel> $channels
     */
    private function tellRider(
        string $riderId,
        string $templateKey,
        array $data,
        array $channels,
        Priority $priority,
        string $correlationId,
    ): void {
        try {
            // Notifications address an *account*, not a rider record. Resolving
            // it here rather than putting the user id on the event keeps the
            // account identifier off the bus, where every subscriber can read
            // it.
            $userId = $this->riders->summary($riderId)['user_id'] ?? null;

            if ($userId === null) {
                return;
            }

            $this->notifications->notify(
                $userId,
                NotificationCategory::Delivery,
                $templateKey,
                $data,
                $channels,
                $priority,
                null,
                $correlationId,
            );
        } catch (Throwable) {
            // See the class docblock: a notification is a side effect of a
            // decision that has already been committed, and rolling that
            // decision back because a push failed would be worse than the
            // missed push.
        }
    }
}
