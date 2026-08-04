<?php

declare(strict_types=1);

namespace EruoFood\Admin\Domain\Enum;

/** Whether an admin account (or a moderated user) is active or suspended. */
enum AccountStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
}
