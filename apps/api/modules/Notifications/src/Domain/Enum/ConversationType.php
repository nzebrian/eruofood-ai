<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Domain\Enum;

/** The kind of conversation, which determines who may participate. */
enum ConversationType: string
{
    case CustomerRestaurant = 'customer_restaurant';
    case CustomerVendor = 'customer_vendor';
    case CustomerRider = 'customer_rider';
    case AdminUser = 'admin_user';
    case Group = 'group'; // group announcements
}
