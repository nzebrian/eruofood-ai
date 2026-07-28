<?php

declare(strict_types=1);

namespace EruoFood\Analytics\Domain\Enum;

/** The analytics area a metric belongs to. */
enum AnalyticsCategory: string
{
    case Revenue = 'revenue';
    case Sales = 'sales';
    case Orders = 'orders';
    case Customers = 'customers';
    case Products = 'products';
    case Vendors = 'vendors';
    case Ai = 'ai';
    case Nutrition = 'nutrition';
    case Financial = 'financial';
    case Operational = 'operational';
}
