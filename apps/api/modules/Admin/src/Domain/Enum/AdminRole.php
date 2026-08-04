<?php

declare(strict_types=1);

namespace EruoFood\Admin\Domain\Enum;

/**
 * The platform's back-office roles. A role is a named bundle of permissions
 * (see {@see \EruoFood\Admin\Domain\Rbac\Permission}); an account may hold
 * several roles plus extra individual grants. SuperAdmin implicitly has every
 * permission.
 */
enum AdminRole: string
{
    case SuperAdmin = 'super_admin';
    case Admin = 'admin';
    case Moderator = 'moderator';
    case ContentManager = 'content_manager';
    case CustomerSupport = 'customer_support';
    case FinanceManager = 'finance_manager';
    case RestaurantManager = 'restaurant_manager';
    case VendorManager = 'vendor_manager';
    case OperationsManager = 'operations_manager';

    public function isSuper(): bool
    {
        return $this === self::SuperAdmin;
    }

    public function label(): string
    {
        return ucwords(str_replace('_', ' ', $this->value));
    }
}
