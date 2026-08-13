<?php

declare(strict_types=1);

namespace EruoFood\Verification\Domain\Attempt;

use DateTimeImmutable;
use EruoFood\Verification\Domain\Enum\ProviderName;
use EruoFood\Verification\Domain\Enum\RejectionReason;
use EruoFood\Verification\Domain\Enum\VerificationStatus;

/**
 * One pass at verifying a case with one provider session.
 *
 * A case can be attempted several times — a blurred document, an expired
 * licence, a provider outage — and each attempt keeps its own provider
 * reference and verdict. Separating attempts from the case is what makes
 * "rejected twice for a mismatched face, then approved" answerable, and it
 * gives the provider reference a natural place to be unique.
 */
final class VerificationAttempt
{
    private function __construct(
        private readonly string $id,
        private readonly string $caseId,
        private readonly ProviderName $provider,
        private readonly string $providerReference,
        private VerificationStatus $status,
        private ?string $rawProviderStatus,
        private ?RejectionReason $reasonCode,
        private readonly DateTimeImmutable $startedAt,
        private ?DateTimeImmutable $decidedAt,
    ) {
    }

    public static function start(
        string $id,
        string $caseId,
        ProviderName $provider,
        string $providerReference,
        DateTimeImmutable $now,
    ): self {
        return new self($id, $caseId, $provider, $providerReference, VerificationStatus::Pending, null, null, $now, null);
    }

    public static function reconstitute(
        string $id,
        string $caseId,
        ProviderName $provider,
        string $providerReference,
        VerificationStatus $status,
        ?string $rawProviderStatus,
        ?RejectionReason $reasonCode,
        DateTimeImmutable $startedAt,
        ?DateTimeImmutable $decidedAt,
    ): self {
        return new self($id, $caseId, $provider, $providerReference, $status, $rawProviderStatus, $reasonCode, $startedAt, $decidedAt);
    }

    /**
     * Record what the provider said.
     *
     * The provider's own status string is kept verbatim alongside our mapped
     * status: when a provider adds a value we have not mapped, the raw string
     * is what lets support explain the case rather than guess at it.
     */
    public function decide(
        VerificationStatus $status,
        string $rawProviderStatus,
        ?RejectionReason $reason,
        DateTimeImmutable $now,
    ): void {
        $this->status = $status;
        $this->rawProviderStatus = $rawProviderStatus;
        $this->reasonCode = $reason;

        if (! $status->isInFlight()) {
            $this->decidedAt = $now;
        }
    }

    public function id(): string
    {
        return $this->id;
    }

    public function caseId(): string
    {
        return $this->caseId;
    }

    public function provider(): ProviderName
    {
        return $this->provider;
    }

    public function providerReference(): string
    {
        return $this->providerReference;
    }

    public function status(): VerificationStatus
    {
        return $this->status;
    }

    public function rawProviderStatus(): ?string
    {
        return $this->rawProviderStatus;
    }

    public function reasonCode(): ?RejectionReason
    {
        return $this->reasonCode;
    }

    public function startedAt(): DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function decidedAt(): ?DateTimeImmutable
    {
        return $this->decidedAt;
    }
}
