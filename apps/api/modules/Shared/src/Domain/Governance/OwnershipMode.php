<?php

declare(strict_types=1);

namespace EruoFood\Shared\Domain\Governance;

/**
 * How many humans actually govern this repository.
 *
 * ## Why governance needs to know
 *
 * The rulesets prepared in M29-A assume more than one person: one approving
 * review, code-owner review required, and a FINANCE owner who is not the
 * repository owner. M29-H established that this repository has exactly one
 * account with access, and that account authors every commit.
 *
 * Applied unchanged to that repository, those rules do not produce strong
 * governance. They produce a repository nobody can merge into — GitHub forbids
 * approving your own pull request — and a code-owner requirement pointed at a
 * CODEOWNERS file in which every rule is commented out, which is the M29-A
 * defect restored.
 *
 * ## Deferred is not satisfied
 *
 * The distinction this enum exists to hold is between a control that is *off
 * and recorded as off*, and a control that is *reported as on while enforcing
 * nothing*. {@see self::SoleOwner} switches nothing off in the automated gates —
 * lint, static analysis, tests, migrations, Redis, financial concurrency, secret
 * scanning, dependency audit and workflow integrity all still run and still
 * block. What it defers is the part that requires a second human to exist, and
 * it requires the validator to say so on every run.
 *
 * A mode that quietly relaxed a check would be worse than no mode at all.
 */
enum OwnershipMode: string
{
    /**
     * One real human. Automated controls fully enforced, human review deferred.
     *
     * Not a weaker tier — a truthful one. The alternative on a single-maintainer
     * repository is a ruleset that blocks every merge, which gets disabled
     * within a week and takes the rest of the governance with it.
     */
    case SoleOwner = 'SOLE_OWNER';

    /**
     * Two or more real humans. The full M29-A policy applies.
     */
    case MultiPerson = 'MULTI_PERSON';

    /**
     * Whether human approvals can be required at all.
     *
     * Under {@see self::SoleOwner} the answer is no, and the reason is GitHub's,
     * not this project's: an author cannot approve their own pull request.
     */
    public function supportsIndependentReview(): bool
    {
        return $this === self::MultiPerson;
    }

    /**
     * The approval count the live ruleset must carry for this mode.
     *
     * Exact rather than a minimum: under SoleOwner anything above zero blocks
     * every merge, and under MultiPerson zero would silently discard the review
     * requirement the mode exists to assert.
     */
    public function requiredApprovingReviewCount(): int
    {
        return $this === self::MultiPerson ? 1 : 0;
    }

    /**
     * Whether `require_code_owner_review` may be true.
     *
     * False under SoleOwner because CODEOWNERS is inert. Requiring review from
     * owners a file does not resolve is the failure M29-A found.
     */
    public function supportsCodeOwnerReview(): bool
    {
        return $this === self::MultiPerson;
    }

    /**
     * Whether FINANCE must be somebody other than the repository owner.
     *
     * Under SoleOwner there is nobody else, so the requirement is recorded as
     * deferred rather than dropped. {@see IdentityPolicy} downgrades the finding
     * from error to warning and renames it, so the gap stays visible in every
     * report instead of disappearing.
     */
    public function requiresIndependentFinanceOwner(): bool
    {
        return $this === self::MultiPerson;
    }

    /** The prepared ruleset artifact this mode is applied from. */
    public function mainRulesetArtifact(): string
    {
        return $this === self::MultiPerson
            ? 'main-ruleset.json'
            : 'main-ruleset.sole-owner.json';
    }

    /**
     * The banner every governance report prints, so a reader never has to infer
     * which controls are live.
     *
     * @return list<string>
     */
    public function summaryLines(): array
    {
        if ($this === self::MultiPerson) {
            return [
                'MULTI_PERSON MODE',
                'Automated controls:      ACTIVE',
                'Independent human review: REQUIRED',
                'CODEOWNERS enforcement:   REQUIRED',
                'Finance four-eyes review: REQUIRED',
                'Reason: the repository declares more than one human participant',
            ];
        }

        return [
            'SOLE_OWNER MODE',
            'Automated controls:      ACTIVE',
            'Independent human review: DEFERRED',
            'CODEOWNERS enforcement:   DEFERRED',
            'Finance four-eyes review: DEFERRED',
            'Reason: repository currently has one real human owner',
        ];
    }
}
