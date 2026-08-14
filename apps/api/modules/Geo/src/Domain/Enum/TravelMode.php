<?php

declare(strict_types=1);

namespace EruoFood\Geo\Domain\Enum;

/**
 * How the journey is made. Part of every route cache key: a motorbike and a car
 * do not take the same path through Lagos, and must not share a cached answer.
 */
enum TravelMode: string
{
    case Driving = 'driving';
    case TwoWheeler = 'two_wheeler';
    case Bicycle = 'bicycle';
    case Walking = 'walking';

    /** Whether live traffic meaningfully changes the answer for this mode. */
    public function isTrafficSensitive(): bool
    {
        return in_array($this, [self::Driving, self::TwoWheeler], true);
    }
}
