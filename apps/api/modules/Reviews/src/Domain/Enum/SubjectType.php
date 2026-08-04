<?php

declare(strict_types=1);

namespace EruoFood\Reviews\Domain\Enum;

/**
 * What is being reviewed. The subject is referenced by (type, id) — a soft
 * reference into the owning context; Reviews never touches that context's tables.
 */
enum SubjectType: string
{
    case Product = 'product';
    case Food = 'food';
    case Recipe = 'recipe';
    case Vendor = 'vendor';
    case Restaurant = 'restaurant';
    case Rider = 'rider';
}
