<?php

declare(strict_types=1);

namespace EruoFood\Analytics\Domain\Enum;

/** The named dashboards the platform assembles. */
enum DashboardType: string
{
    case Executive = 'executive';
    case Operations = 'operations';
    case Finance = 'finance';
    case Restaurant = 'restaurant';
    case Vendor = 'vendor';
    case Admin = 'admin';
}
