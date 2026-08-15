<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Domain\Enum;

/**
 * Why a rider was eliminated before scoring.
 *
 * Named reasons rather than a boolean, because "no eligible riders" is useless
 * to an operator at 8pm on a Friday. "Eleven riders nearby: nine stale
 * location, two expired insurance" is something they can act on — and the
 * difference between a platform outage and a fleet-paperwork problem.
 *
 * Recorded per attempt in `dispatch_attempts.rejection_breakdown`.
 */
enum RejectionReason: string
{
    case RiderInactive = 'rider_inactive';
    case RiderSuspended = 'rider_suspended';
    case RiderUnavailable = 'rider_unavailable';
    case RiderHasActiveDelivery = 'rider_has_active_delivery';
    case RiderNotVerified = 'rider_not_verified';
    case NoActiveVehicle = 'no_active_vehicle';
    case VehicleNotVerified = 'vehicle_not_verified';
    case VehicleDocumentsExpired = 'vehicle_documents_expired';
    case VehicleUnsuitable = 'vehicle_unsuitable';
    case LocationStale = 'location_stale';
    case LocationInaccurate = 'location_inaccurate';
    case OutsideServiceArea = 'outside_service_area';
    case MissingCapability = 'missing_capability';
    case AlreadyDeclined = 'already_declined';
    case FairnessCapReached = 'fairness_cap_reached';

    /**
     * Whether the rider could fix this themselves.
     *
     * Drives what a rider is told: "your insurance has expired" is actionable,
     * "you already have a delivery" is not a problem at all.
     */
    public function isRiderActionable(): bool
    {
        return match ($this) {
            self::NoActiveVehicle, self::VehicleNotVerified,
            self::VehicleDocumentsExpired, self::LocationStale,
            self::RiderNotVerified => true,
            default => false,
        };
    }
}
