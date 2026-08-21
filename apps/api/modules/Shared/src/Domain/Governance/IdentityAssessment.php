<?php

declare(strict_types=1);

namespace EruoFood\Shared\Domain\Governance;

/**
 * What {@see IdentityPolicy} concluded about one identity configuration.
 *
 * Three things, kept separate because conflating them is how governance ends up
 * asserting more than it knows:
 *
 * - **findings** — what is wrong, locally provable.
 * - **resolved / unresolved** — which roles have a usable identity.
 * - **external requirements** — what remains true regardless of how clean the
 *   first two are. This list is never empty. See {@see self::externalRequirements()}.
 */
final readonly class IdentityAssessment
{
    /**
     * @param list<IdentityFinding> $findings
     * @param array<string, list<string>> $resolved role value => owner handles
     * @param list<string> $unresolvedRoles
     */
    public function __construct(
        public ActivationState $state,
        public array $findings,
        public array $resolved,
        public array $unresolvedRoles,
    ) {
    }

    /** @return list<IdentityFinding> */
    public function errors(): array
    {
        return array_values(array_filter($this->findings, static fn (IdentityFinding $f): bool => $f->isError()));
    }

    /** @return list<IdentityFinding> */
    public function warnings(): array
    {
        return array_values(array_filter($this->findings, static fn (IdentityFinding $f): bool => ! $f->isError()));
    }

    /**
     * The things this repository can never establish about itself.
     *
     * Returned unconditionally — for a flawless configuration exactly as for an
     * empty one. That is the point. A resolved identity file says somebody
     * wrote a handle down; it says nothing about whether the account exists, can
     * push, or is being consulted by GitHub. Callers render these as
     * EXTERNAL / ADMIN REQUIRED and are structurally unable to turn them into a
     * PASS, because there is no code path that removes an entry from this list.
     *
     * Scoped to identity. Whether the rulesets themselves exist and are
     * enforcing is equally unprovable here, but it belongs to the ruleset
     * checks rather than to this assessment, and listing it twice trains
     * readers to skim the list.
     *
     * @return list<string>
     */
    public function externalRequirements(): array
    {
        return [
            'each configured account actually exists on GitHub',
            'each configured account has write access (GitHub silently ignores a code owner who cannot push)',
            'CODEOWNER identities resolve (GET /codeowners/errors returns zero)',
            'the release actor id is one GitHub will accept in bypass_actors',
        ];
    }

    public function isReadyForActivation(): bool
    {
        return $this->state === ActivationState::ReadyForActivation;
    }
}
