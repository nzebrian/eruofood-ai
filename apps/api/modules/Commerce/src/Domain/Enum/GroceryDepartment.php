<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Domain\Enum;

/**
 * Grocery departments used to organise the grocery catalogue: fresh produce,
 * pantry staples, beverages, frozen foods and household items.
 */
enum GroceryDepartment: string
{
    case Produce = 'produce';       // fresh fruit & vegetables
    case Pantry = 'pantry';         // dry/pantry staples
    case Beverages = 'beverages';
    case Frozen = 'frozen';
    case Household = 'household';
    case Other = 'other';
}
