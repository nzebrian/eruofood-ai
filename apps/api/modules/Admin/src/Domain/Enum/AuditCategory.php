<?php

declare(strict_types=1);

namespace EruoFood\Admin\Domain\Enum;

/** The category of an audit-log entry, for filtering the compliance history. */
enum AuditCategory: string
{
    case Auth = 'auth';               // login history, 2FA, password
    case Security = 'security';       // suspicious activity, security events
    case Config = 'config';           // configuration changes
    case Content = 'content';         // CMS changes
    case Users = 'users';             // user moderation
    case Operations = 'operations';   // vendor/restaurant ops
    case Support = 'support';         // support actions
    case Rbac = 'rbac';               // role/permission changes, impersonation
    case DataAccess = 'data_access';  // sensitive data access
}
