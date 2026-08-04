<?php

declare(strict_types=1);

namespace EruoFood\Catalog\Domain\Enum;

/** Units used for recipe ingredient quantities. */
enum MeasurementUnit: string
{
    case Gram = 'g';
    case Kilogram = 'kg';
    case Milliliter = 'ml';
    case Liter = 'l';
    case Cup = 'cup';
    case Tablespoon = 'tbsp';
    case Teaspoon = 'tsp';
    case Piece = 'piece';
    case Pinch = 'pinch';
    case Handful = 'handful';
    case Wrap = 'wrap';
    case ToTaste = 'to_taste';
}
