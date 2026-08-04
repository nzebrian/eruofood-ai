<?php

declare(strict_types=1);

namespace EruoFood\Support\Domain\Crm;

/**
 * A customer's value segment, derived from their order count against configured
 * thresholds. Used to prioritise support and target CRM actions.
 */
enum CustomerSegment: string
{
    case New = 'new';
    case Active = 'active';
    case Loyal = 'loyal';
    case Vip = 'vip';

    /**
     * Resolve a segment from an order count and a threshold map
     * (segment value => minimum orders), highest threshold first.
     *
     * @param array<string, int> $thresholds
     */
    public static function fromOrderCount(int $orders, array $thresholds): self
    {
        arsort($thresholds);
        foreach ($thresholds as $segment => $min) {
            if ($orders >= $min) {
                return self::from($segment);
            }
        }

        return self::New;
    }
}
