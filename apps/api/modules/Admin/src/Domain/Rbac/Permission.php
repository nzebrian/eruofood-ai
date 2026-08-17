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

    /*
     * Maps & Geolocation, split two ways.
     *
     * Reading provider health, cost and geocoding coverage is an operational
     * need that many roles have. Overruling a location's verification status —
     * confirming a pin or marking one disputed — changes where the platform
     * sends riders, so it is a narrower power held by the roles that actually
     * run deliveries.
     */
    public const GEO_READ = 'geo.read';

    public const GEO_MANAGE = 'geo.manage';

    /*
     * Dispatch, split two ways for the same reason Geo is.
     *
     * Reading the dispatch queue, why a search failed and who is carrying what
     * is an operational need many roles have — support answering "where is my
     * order?" needs it as much as operations does.
     *
     * `dispatch.manage` is the narrow one. It takes a delivery off one rider
     * and gives it to another, cancels a search, or overrides the engine's
     * decision. Those change who earns and who eats, so they are held by the
     * roles that actually run deliveries, and every use of them is audited.
     */
    public const DISPATCH_READ = 'dispatch.read';

    public const DISPATCH_MANAGE = 'dispatch.manage';

    /*
     * Finance, split six ways.
     *
     * `finance.read` used to guard the whole back-office finance group,
     * including `POST admin/settlements` — which transfers money to a bank
     * account. Four roles hold `finance.read`, one of them CustomerSupport,
     * whose job is answering "where is my refund?". Reading a ledger and moving
     * money out of it are not the same power, and a permission whose name says
     * `read` must never have authorised the second.
     *
     * The split is by *consequence*, not by screen:
     *
     * - `finance.read` sees money. It moves none, and never will again.
     * - `finance.settle` decides that a computed settlement is correct and may
     *   proceed. It still moves nothing on its own.
     * - `finance.payout` performs the transfer. Separated from `settle` so that
     *   deciding and doing are two acts — and the domain additionally requires
     *   two *people* (see SeparationOfDuties).
     * - `finance.reconcile` investigates a discrepancy. Investigation is
     *   read-heavy and safe, so it is granted more widely than the two above.
     * - `finance.adjust` closes a discrepancy by posting a compensating entry.
     *   This is the power to make the books say something different from what
     *   they said, so it is SuperAdmin only.
     * - `finance.reverse` undoes a settlement that already paid out. Likewise.
     */
    public const FINANCE_SETTLE = 'finance.settle';

    public const FINANCE_PAYOUT = 'finance.payout';

    public const FINANCE_RECONCILE = 'finance.reconcile';

    public const FINANCE_ADJUST = 'finance.adjust';

    public const FINANCE_REVERSE = 'finance.reverse';

    /**
     * The permissions that authorise money leaving the platform, or the books
     * being changed to say something new.
     *
     * Named as a set rather than left implicit so a test can assert that no
     * money-moving route is reachable with a read-only permission, and so a
     * future permission cannot be added to the finance group without a decision
     * about which side of this line it falls on.
     *
     * @return list<string>
     */
    public static function moneyMoving(): array
    {
        return [
            self::FINANCE_SETTLE, self::FINANCE_PAYOUT,
            self::FINANCE_ADJUST, self::FINANCE_REVERSE,
        ];
    }

    /**
     * Every finance permission that authorises a *write* of any kind.
     *
     * Wider than {@see moneyMoving()} by exactly one entry: `finance.reconcile`
     * changes a case's state without moving a penny. The two lists exist
     * separately because the assertions they back are different — "no money
     * moves behind a read permission" and "nothing is written behind a read
     * permission" — and collapsing them would make the second silently weaken
     * the first.
     *
     * @return list<string>
     */
    public static function financeWriting(): array
    {
        return [...self::moneyMoving(), self::FINANCE_RECONCILE];
    }

    /** @return list<string> every permission the platform defines */
    public static function all(): array
    {
        return [
            self::RBAC_MANAGE, self::IMPERSONATE, self::CONTENT_MANAGE,
            self::CONFIG_READ, self::CONFIG_WRITE, self::USERS_READ, self::USERS_MODERATE,
            self::OPS_READ, self::OPS_APPROVE, self::SUPPORT_READ, self::SUPPORT_MANAGE,
            self::FINANCE_READ, self::AUDIT_READ,
            self::VERIFICATION_READ, self::VERIFICATION_REVIEW, self::VERIFICATION_PII,
            self::GEO_READ, self::GEO_MANAGE,
            self::DISPATCH_READ, self::DISPATCH_MANAGE,
            self::FINANCE_SETTLE, self::FINANCE_PAYOUT, self::FINANCE_RECONCILE,
            self::FINANCE_ADJUST, self::FINANCE_REVERSE,
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
                // FINANCE_READ and no more. A general administrator can see the
                // ledger, the settlement queue and every payout attempt, and
                // can move none of it. Moving money is FinanceManager's job.
                // Deliberately READ and REVIEW without PII: a general
                // administrator can unblock a merchant or a rider without ever
                // opening their identity documents.
                self::VERIFICATION_READ, self::VERIFICATION_REVIEW,
                self::GEO_READ, self::GEO_MANAGE,
                self::DISPATCH_READ, self::DISPATCH_MANAGE,
            ],
            AdminRole::Moderator => [self::USERS_READ, self::USERS_MODERATE, self::CONTENT_MANAGE],
            AdminRole::ContentManager => [self::CONTENT_MANAGE, self::CONFIG_READ],
            AdminRole::CustomerSupport => [
                self::SUPPORT_READ, self::SUPPORT_MANAGE, self::USERS_READ,
                // Answering "where is my order?" needs the dispatch queue.
                // Taking the delivery off the rider does not.
                self::DISPATCH_READ,
            ],
            // The only non-super role that may move money. It holds `settle`
            // and `payout` as two separate grants rather than one because the
            // domain requires two different people to use them (see
            // SeparationOfDuties) — a single "finance.manage" would have made
            // that rule unenforceable at the permission layer.
            //
            // It deliberately does NOT hold `adjust` or `reverse`: changing what
            // the books say, and clawing back a completed payout, are
            // SuperAdmin-only.
            AdminRole::FinanceManager => [
                self::FINANCE_READ, self::AUDIT_READ, self::CONFIG_READ,
                self::FINANCE_SETTLE, self::FINANCE_PAYOUT, self::FINANCE_RECONCILE,
            ],
            AdminRole::RestaurantManager, AdminRole::VendorManager => [
                self::OPS_READ, self::OPS_APPROVE, self::USERS_READ,
                // Health and coverage only: a vendor manager can see that
                // geocoding is degraded without being able to move a pin.
                self::GEO_READ,
                // Likewise dispatch: a vendor manager can see that their order
                // is still looking for a rider without being able to take it
                // off the one who has it.
                self::DISPATCH_READ,
            ],
            AdminRole::OperationsManager => [
                self::OPS_READ, self::OPS_APPROVE, self::SUPPORT_READ, self::CONFIG_READ, self::AUDIT_READ,
                self::VERIFICATION_READ,
                // Operations owns delivery, so it owns correcting the addresses
                // riders are sent to — and reassigning a delivery when a rider
                // drops out.
                self::GEO_READ, self::GEO_MANAGE,
                self::DISPATCH_READ, self::DISPATCH_MANAGE,
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
