<?php

declare(strict_types=1);

namespace EruoFood\Shared\Domain\Governance;

/**
 * The identity slots that repository governance needs filled before it can be
 * switched on.
 *
 * These are not job titles. Each case corresponds to a `<OWNER:...>` token that
 * M29-A left in `.github/CODEOWNERS`, or — in the single case of
 * {@see self::ReleaseActor} — to the bypass actor that
 * `production-tags-ruleset.json` needs before anybody can cut a release.
 *
 * The set is closed on purpose. An unknown role in an identity file is rejected
 * rather than ignored, because the failure it hides is silent: a typo
 * (`FINANC`) leaves the real role unfilled while the file looks complete, which
 * is precisely the shape of the defect M29-A found in CODEOWNERS. Adding a role
 * means adding a case here *and* a token in CODEOWNERS, in one change, so the
 * two cannot drift.
 */
enum GovernanceRole: string
{
    case Maintainers = 'MAINTAINERS';
    case Api = 'API';
    case Finance = 'FINANCE';
    case Web = 'WEB';
    case Mobile = 'MOBILE';
    case Platform = 'PLATFORM';
    case Governance = 'GOVERNANCE';

    /**
     * Not a code owner.
     *
     * This one is shaped differently from every other case and that difference
     * matters. A code owner is a `@handle` in a text file; a release actor is a
     * numeric `actor_id` plus an `actor_type` inside a ruleset's
     * `bypass_actors`. GitHub will not accept one in place of the other, and a
     * schema that treats them alike produces a config that validates locally
     * and is rejected by the API.
     */
    case ReleaseActor = 'RELEASE_ACTOR';

    /**
     * The roles that appear as `<OWNER:...>` tokens in CODEOWNERS.
     *
     * @return list<self>
     */
    public static function codeownerRoles(): array
    {
        // Built by appending rather than filtered, so the result is a list by
        // construction. `array_filter` preserves keys, and it only happens to
        // return a list here because ReleaseActor is declared last — moving the
        // case would put a hole in the array and silently change the shape.
        $roles = [];

        foreach (self::cases() as $role) {
            if ($role->isCodeownerRole()) {
                $roles[] = $role;
            }
        }

        return $roles;
    }

    public function isCodeownerRole(): bool
    {
        return $this !== self::ReleaseActor;
    }

    /** The token this role occupies in `.github/CODEOWNERS`. */
    public function token(): string
    {
        return '<OWNER:'.$this->value.'>';
    }

    /**
     * Whether an unreviewed change to this role's paths can move money.
     *
     * Only {@see self::Finance}. M27 split finance permissions by consequence
     * and M28 proved the kill switches stop payment; both are undone by one
     * merge that nobody with financial context read. The identity policy holds
     * this role to a stricter standard than the rest for that reason alone.
     */
    public function guardsFinancialPaths(): bool
    {
        return $this === self::Finance;
    }
}
