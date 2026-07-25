<?php

declare(strict_types=1);

namespace EruoFood\Catalog\Domain\Enum;

/** High-level grouping of Nigerian foods. */
enum CategoryType: string
{
    case Soup = 'soup';
    case Swallow = 'swallow';
    case Rice = 'rice';
    case Protein = 'protein';
    case Snack = 'snack';
    case StreetFood = 'street_food';
    case Drink = 'drink';
    case Dessert = 'dessert';
    case Breakfast = 'breakfast';
    case SideDish = 'side_dish';

    public function label(): string
    {
        return ucwords(str_replace('_', ' ', $this->value));
    }
}
