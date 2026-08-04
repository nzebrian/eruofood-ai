<?php

declare(strict_types=1);

namespace EruoFood\Catalog\Domain\Enum;

/** Nigeria's six geopolitical zones — the coarse "region" of a food. */
enum FoodRegion: string
{
    case NorthCentral = 'north_central';
    case NorthEast = 'north_east';
    case NorthWest = 'north_west';
    case SouthEast = 'south_east';
    case SouthSouth = 'south_south';
    case SouthWest = 'south_west';
    case NationWide = 'nationwide';

    public function label(): string
    {
        return ucwords(str_replace('_', ' ', $this->value));
    }
}
