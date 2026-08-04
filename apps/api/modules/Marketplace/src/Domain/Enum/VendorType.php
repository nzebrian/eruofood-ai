<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Domain\Enum;

/** The kind of food business a vendor is. */
enum VendorType: string
{
    case Restaurant = 'restaurant';
    case MarketVendor = 'market_vendor';
    case HomeKitchen = 'home_kitchen';
    case CloudKitchen = 'cloud_kitchen';
}
