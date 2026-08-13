<?php

declare(strict_types=1);

namespace EruoFood\Admin\Domain\Rbac;

use EruoFood\Admin\Domain\Enum\AdminRole;

/**
 * The fine-grained permission catalogue and the role → permissions map. Kept as
 * a small, framework-free domain service so both the authorisation checks and
 * the admin UI (permission groups) read from one source of truth. SuperAdmin
 * bypasses the map and holds every permission.
 */
final class Permission
{
    // Permission groups (prefix = group).
    public const RBAC_MANAGE = 'rbac.manage';
    public const IMPERSONATE = 'rbac.impersonate';
    public const CONTENT_MANAGE = 'content.manage';
    public const CONFIG_READ = 'config.read';
    public const CONFIG_WRITE = 'config.write';
    public const USERS_READ = 'users.read';
    public const USERS_MODERATE = 'users.moderate';
    public const OPS_READ = 'ops.read';
    public const OPS_APPROVE = 'ops.approve';
    public const SUPPORT_READ = 'support.read';
    public const SUPPORT_MANAGE = 'support.manage';
    public const FINANCE_READ = 'finance.read';
    public const AUDIT_READ = 'audit.read';

    /*
     * Verification (KYC/KYB), split three ways on purpose.
     *
     * Seeing that a case is waiting, deciding it, and opening the identity data
     * inside it are three different powers. Most back-office work needs only the
     * first — a queue can be cleared without anyone reading a document — so
     * VERIFICATION_PII is granted narrowly and audited on every use.
     */
    public const VERIFICATION_READ = 'verification.read';

    public const VERIFICATION_REVIEW = 'verification.review';

    public const VERIFICATION_PII = 'verification.pii';

    /** @return list<string> every permission the platform defines */
    public static function all(): array
    {
        return [
            self::RBAC_MANAGE, self::IMPERSONATE, self::CONTENT_MANAGE,
            self::CONFIG_READ, self::CONFIG_WRITE, self::USERS_READ, self::USERS_MODERATE,
            self::OPS_READ, self::OPS_APPROVE, self::SUPPORT_READ, self::SUPPORT_MANAGE,
            self::FINANCE_READ, self::AUDIT_READ,
            self::VERIFICATION_READ, self::VERIFICATION_REVIEW, self::VERIFICATION_PII,
        ];
    }

    /**
     * The permissions granted by a role.
     *
     * @return list<string>
     */
    public static function forRole(AdminRole $role): array
    {
        return match ($role) {
            AdminRole::SuperAdmin => self::all(),
            AdminRole::Admin => [
                self::CONTENT_MANAGE, self::CONFIG_READ, self::CONFIG_WRITE,
                self::USERS_READ, self::USERS_MODERATE, self::OPS_READ, self::OPS_APPROVE,
                self::SUPPORT_READ, self::SUPPORT_MANAGE, self::FINANCE_READ, self::AUDIT_READ,
                self::IMPERSONATE,
                // Deliberately READ and REVIEW without PII: a general
                // administrator can unblock a merchant or a rider without ever
                // opening their identity documents.
                self::VERIFICATION_READ, self::VERIFICATION_REVIEW,
            ],
            AdminRole::Moderator => [self::USERS_READ, self::USERS_MODERATE, self::CONTENT_MANAGE],
            AdminRole::ContentManager => [self::CONTENT_MANAGE, self::CONFIG_READ],
            AdminRole::CustomerSupport => [self::SUPPORT_READ, self::SUPPORT_MANAGE, self::USERS_READ],
            AdminRole::FinanceManager => [self::FINANCE_READ, self::AUDIT_READ, self::CONFIG_READ],
            AdminRole::RestaurantManager, AdminRole::VendorManager => [self::OPS_READ, self::OPS_APPROVE, self::USERS_READ],
            AdminRole::OperationsManager => [
                self::OPS_READ, self::OPS_APPROVE, self::SUPPORT_READ, self::CONFIG_READ, self::AUDIT_READ,
                self::VERIFICATION_READ,
            ],
            // The only non-super role holding VERIFICATION_PII.
            AdminRole::ComplianceOfficer => [
                self::VERIFICATION_READ, self::VERIFICATION_REVIEW, self::VERIFICATION_PII,
                self::AUDIT_READ, self::USERS_READ,
            ],
        };
    }

    /**
     * The permission groups (group => permissions) for the admin UI.
     *
     * @return array<string, list<string>>
     */
    public static function groups(): array
    {
        $groups = [];
        foreach (self::all() as $permission) {
            $group = explode('.', $permission)[0];
            $groups[$group][] = $permission;
        }

        return $groups;
    }
}
