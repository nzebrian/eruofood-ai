<?php

declare(strict_types=1);

namespace EruoFood\Verification\Contracts;

/**
 * The one thing other bounded contexts may ask Verification.
 *
 * Marketplace needs to know whether a rider can be dispatched; Commerce whether
 * a store can trade; Identity what assurance level an account holds. All three
 * get an answer here rather than reading Verification's tables, so the storage
 * of regulated data stays behind a single door.
 *
 * Note what this interface deliberately cannot return: names, document numbers,
 * dates of birth, provider references. A consumer that only needs an eligibility
 * decision is given exactly that and no route to anything more — the narrowness
 * is the security control, not an omission.
 *
 * Subject types and statuses cross as plain strings (their enum `value`s) so a
 * consumer takes no compile-time dependency on Verification's domain classes.
 */
interface VerificationStatusQuery
{
    /**
     * The subject's current status for the relevant case type, as a
     * {@see \EruoFood\Verification\Domain\Enum\VerificationStatus} value.
     * Returns `not_started` when no case exists.
     */
    public function statusFor(string $subjectType, string $subjectId): string;

    /** Whether the subject currently satisfies verification. */
    public function isVerified(string $subjectType, string $subjectId): bool;

    /**
     * The account's assurance level, as a
     * {@see \EruoFood\Verification\Domain\Enum\VerificationLevel} value.
     */
    public function levelFor(string $userId): string;

    /**
     * Whether the account's level meets or exceeds $requiredLevel.
     *
     * Provided so callers compare levels through the owning context rather than
     * reimplementing the ranking and drifting out of step with it.
     */
    public function meetsLevel(string $userId, string $requiredLevel): bool;

    /**
     * Whether an activation gate should actually block this subject.
     *
     * Distinct from {@see isVerified()} on purpose, and this is the method
     * consumers want. Being unverified is a fact about the subject; being
     * *blocked* also depends on whether the platform is yet enforcing
     * verification for that population — which during rollout it deliberately is
     * not. Combining the two here keeps that policy in the context that owns it,
     * so a consumer cannot accidentally enforce ahead of the switch by checking
     * verification status directly.
     *
     * @param string|null $businessKind 'restaurant' or 'grocery', so restaurants
     *                                  and groceries can be phased in separately
     */
    public function blocksSubject(string $subjectType, string $subjectId, ?string $businessKind = null): bool;
}
