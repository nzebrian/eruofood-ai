<?php

declare(strict_types=1);

namespace EruoFood\Verification\Infrastructure\Persistence\Eloquent;

use EruoFood\Verification\Application\Service\EligibilityService;
use EruoFood\Verification\Contracts\VerificationStatusQuery;
use EruoFood\Verification\Domain\Enum\CaseType;
use EruoFood\Verification\Domain\Enum\SubjectType;
use EruoFood\Verification\Domain\Enum\VerificationLevel;
use EruoFood\Verification\Domain\Enum\VerificationStatus;
use EruoFood\Verification\Domain\Phone\PhoneChallengeRepository;
use EruoFood\Verification\Infrastructure\Persistence\Eloquent\Model\VerificationCaseModel;

/**
 * The read side other contexts see.
 *
 * Answers status and level questions and nothing else: there is no method here
 * that could return a name, a document number or a provider reference, so a
 * consumer holding this interface has no route to regulated data even by
 * mistake. The narrowness is the control.
 *
 * Reads are projected straight from the case table rather than rehydrating
 * aggregates — these are called on eligibility paths and must stay cheap.
 */
final class EloquentVerificationStatusQuery implements VerificationStatusQuery
{
    public function __construct(
        private readonly EligibilityService $eligibility,
        private readonly PhoneChallengeRepository $phones,
    ) {
    }

    public function blocksSubject(string $subjectType, string $subjectId, ?string $businessKind = null): bool
    {
        $type = SubjectType::tryFrom($subjectType);
        if ($type === null) {
            return false;
        }

        // Restaurants and groceries are both `business` subjects but can be
        // switched on independently, so the business kind selects the flag.
        $enforced = $businessKind !== null
            ? $this->eligibility->enforcedForBusinessKind($businessKind)
            : $this->eligibility->enforcedFor($type);

        return $enforced && ! $this->isVerified($subjectType, $subjectId);
    }

    public function statusFor(string $subjectType, string $subjectId): string
    {
        $type = SubjectType::tryFrom($subjectType);
        if ($type === null) {
            return VerificationStatus::NotStarted->value;
        }

        $caseType = $type === SubjectType::Business ? CaseType::Business : CaseType::Identity;

        $status = VerificationCaseModel::query()
            ->where('subject_type', $type->value)
            ->where('subject_id', $subjectId)
            ->where('case_type', $caseType->value)
            // A verified case wins over a newer in-flight one: starting a
            // reverification must not make somebody instantly ineligible.
            ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [VerificationStatus::Verified->value])
            ->orderByDesc('updated_at')
            ->value('status');

        return is_string($status) ? $status : VerificationStatus::NotStarted->value;
    }

    public function isVerified(string $subjectType, string $subjectId): bool
    {
        return $this->statusFor($subjectType, $subjectId) === VerificationStatus::Verified->value;
    }

    public function levelFor(string $userId): string
    {
        // Strongest claim first. A verified identity case subsumes a confirmed
        // number, so somebody who did full KYC is never asked to also confirm a
        // phone for an operation that only wanted the weaker rung.
        if ($this->isVerified(SubjectType::Customer->value, $userId)) {
            return VerificationLevel::Identity->value;
        }

        if ($this->phones->isVerified($userId)) {
            return VerificationLevel::Phone->value;
        }

        // Where every account starts, and where it stays until an operation
        // actually needs more. Registration demands nothing.
        return VerificationLevel::Basic->value;
    }

    public function meetsLevel(string $userId, string $requiredLevel): bool
    {
        $required = VerificationLevel::tryFrom($requiredLevel);
        $current = VerificationLevel::tryFrom($this->levelFor($userId));

        return $required === null || ($current?->satisfies($required) ?? false);
    }
}
