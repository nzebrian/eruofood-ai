<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Platform Administration, CMS & Operations configuration
|------------------------------------------------------------------------------
| The Admin context owns its own fine-grained RBAC. Any Identity user with the
| coarse "admin" role is treated as a super administrator (the bootstrap admin);
| finer roles are granted as Admin accounts. `bootstrap_super_admins` lets you
| pin specific user ids as super admins regardless of Identity roles.
*/

return [
    // User ids always treated as super administrators (comma-separated env).
    'bootstrap_super_admins' => array_values(array_filter(explode(',', (string) env('ADMIN_SUPER_ADMINS', '')))),

    /*
    | Treat any Identity `admin`-role user as a super administrator.
    |
    | Defaults to FALSE. The Identity role model has three coarse roles
    | (admin/moderator/user) while the back office has nine, each mapped to a
    | specific permission set. Letting the coarse role imply SuperAdmin collapses
    | that model: every holder of an `admin` JWT would gain finance access,
    | impersonation and RBAC management regardless of the roles actually granted
    | to them, and there would be no separation of duties to enforce.
    |
    | Back-office access therefore requires an explicit `admin_accounts` grant,
    | or an id listed in `bootstrap_super_admins` for the very first operator.
    | The flag remains available so an existing deployment can enable it for one
    | release while real admin accounts are provisioned.
    */
    'identity_admin_is_super' => (bool) env('ADMIN_IDENTITY_ADMIN_IS_SUPER', false),

    // Maintenance mode (also togglable at runtime via a setting).
    'maintenance' => [
        'enabled' => (bool) env('ADMIN_MAINTENANCE', false),
        'message' => env('ADMIN_MAINTENANCE_MESSAGE', 'EruoFood is undergoing scheduled maintenance. Please check back soon.'),
    ],

    'regional' => [
        'default_locale' => env('ADMIN_DEFAULT_LOCALE', 'en'),
        'default_timezone' => env('ADMIN_DEFAULT_TZ', 'Africa/Lagos'),
        'default_currency' => env('ADMIN_DEFAULT_CURRENCY', 'NGN'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Domain events the Admin audit log ingests for security/activity history
    | (login history, config changes elsewhere, etc.). Like other event-driven
    | contexts, Admin subscribes by event name — it never reaches into a module.
    |--------------------------------------------------------------------------
    */
    'audit_events' => [
        'identity.user_registered' => 'user.registered',
        'identity.password_changed' => 'user.password_changed',
        'identity.two_factor_enabled' => 'user.two_factor_enabled',
        'identity.email_verified' => 'user.email_verified',
        'payments.payment_failed' => 'security.payment_failed',
        'marketplace.vendor_verified' => 'ops.vendor_verified',

        /*
         * KYC / KYB (M24). The first entry is the one that matters for
         * compliance: every read of regulated identity data lands in the
         * append-only trail, granted or denied, whoever made it — a SuperAdmin
         * keeps the permission, not the ability to look unobserved.
         */
        'verification.sensitive_data_accessed' => 'security.verification_pii_accessed',
        'verification.subject_verified' => 'ops.verification_subject_verified',
        'verification.subject_rejected' => 'ops.verification_subject_rejected',
        'verification.subject_expired' => 'ops.verification_subject_expired',
    ],
];
