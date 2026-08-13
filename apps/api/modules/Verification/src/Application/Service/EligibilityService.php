<?php

declare(strict_types=1);

namespace EruoFood\Verification\Application\Service;

use EruoFood\Verification\Domain\Enum\CaseType;
use EruoFood\Verification\Domain\Enum\SubjectType;
use EruoFood\Verification\Domain\Enum\VerificationStatus;
use EruoFood\Verification\Domain\VerificationCase\CaseRepository;

/**
 * Answers "is this subject verified?" and "does enforcement apply to them?".
 *
 * The two questions are separate on purpose. Verification status is a fact about
 * the subject; enforcement is a deployment decision about whether that fact yet
 * blocks anything. Keeping them apart is what lets the platform record and
 * review verification for an entire existing merchant population *before* the
 * gate is switched on — rather than delisting everyone the moment the code
 * ships.
 *
 * Enforcement resolves per subject kind, each falling back to the master flag,
 * so riders can be gated first and merchants later.
 */
final readonly class EligibilityService
{
    /** @param array<string, mixed> $enforcement */
    public function __construct(
        private CaseRepository $cases,
        private array $enforcement,
    ) {
    }

    /** Whether the subject currently holds a verified case of the given type. */
    public function isVerified(SubjectType $subjectType, string $subjectId, ?CaseType $caseType = null): bool
    {
        return $this->statusFor($subjectType, $subjectId, $caseType)->isVerified();
    }

    public function statusFor(SubjectType $subjectType, string $subjectId, ?CaseType $caseType = null): VerificationStatus
    {
        $type = $caseType ?? $this->defaultCaseTypeFor($subjectType);
        $case = $this->cases->findLatestFor($subjectType, $subjectId, $type);

        return $case?->status() ?? VerificationStatus::NotStarted;
    }

    /**
     * Whether an unverified subject of this kind should actually be blocked.
     *
     * Returning false does not mean the subject is verified — it means the
     * platform is not yet enforcing verification for that population.
     */
    public function enforcedFor(SubjectType $subjectType): bool
    {
        $master = (bool) ($this->enforcement['enabled'] ?? false);

        $override = match ($subjectType) {
            SubjectType::Rider => $this->enforcement['riders'] ?? null,
            SubjectType::Business => $this->enforcement['restaurants'] ?? null,
            SubjectType::Customer => null,
        };

        return $override === null ? $master : filter_var($override, FILTER_VALIDATE_BOOL);
    }

    /** Per-kind enforcement for businesses, which split into restaurants and groceries. */
    public function enforcedForBusinessKind(string $businessKind): bool
    {
        $master = (bool) ($this->enforcement['enabled'] ?? false);

        $override = match ($businessKind) {
            'restaurant' => $this->enforcement['restaurants'] ?? null,
            'grocery' => $this->enforcement['groceries'] ?? null,
            default => null,
        };

        return $override === null ? $master : filter_var($override, FILTER_VALIDATE_BOOL);
    }

    /**
     * The decision an activation gate needs: blocked only when the subject is
     * unverified *and* enforcement applies to them.
     */
    public function blocks(SubjectType $subjectType, string $subjectId, ?CaseType $caseType = null): bool
    {
        return $this->enforcedFor($subjectType) && ! $this->isVerified($subjectType, $subjectId, $caseType);
    }

    private function defaultCaseTypeFor(SubjectType $subjectType): CaseType
    {
        return $subjectType === SubjectType::Business ? CaseType::Business : CaseType::Identity;
    }
}
