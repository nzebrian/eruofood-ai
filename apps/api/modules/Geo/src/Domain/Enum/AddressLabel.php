<?php

declare(strict_types=1);

namespace EruoFood\Geo\Domain\Enum;

/** The common labels, with `Other` carrying a free-text name for everything else. */
enum AddressLabel: string
{
    case Home = 'home';
    case Work = 'work';
    case Other = 'other';

    public function requiresCustomName(): bool
    {
        return $this === self::Other;
    }
}
