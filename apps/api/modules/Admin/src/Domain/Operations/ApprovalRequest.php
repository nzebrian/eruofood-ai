<?php

declare(strict_types=1);

namespace EruoFood\Admin\Domain\Operations;

use DateTimeImmutable;
use EruoFood\Admin\Domain\Exception\AdminInvalidState;

/**
 * A request awaiting an operations decision — a vendor onboarding, a business
 * verification, a compliance review. Admin owns only the review/decision record;
 * the vendor itself lives in Marketplace/Commerce and is referenced by id. When
 * a decision is made an event is published so the owning context reacts.
 */
final class ApprovalRequest
{
    /**
     * @param array<string, scalar|null> $details
     */
    private function __construct(
        private readonly string $id,
        private readonly string $subjectType,
        private readonly string $subjectId,
        private readonly ApprovalKind $kind,
        private array $details,
        private ApprovalStatus $status,
        private ?string $decidedBy,
        private ?string $note,
        private readonly DateTimeImmutable $submittedAt,
        private ?DateTimeImmutable $decidedAt,
    ) {
    }

    /**
     * @param array<string, scalar|null> $details
     */
    public static function submit(
        string $id,
        string $subjectType,
        string $subjectId,
        ApprovalKind $kind,
        array $details,
        DateTimeImmutable $now,
    ): self {
        return new self($id, $subjectType, $subjectId, $kind, $details, ApprovalStatus::Pending, null, null, $now, null);
    }

    /**
     * @param array<string, scalar|null> $details
     */
    public static function reconstitute(
        string $id,
        string $subjectType,
        string $subjectId,
        ApprovalKind $kind,
        array $details,
        ApprovalStatus $status,
        ?string $decidedBy,
        ?string $note,
        DateTimeImmutable $submittedAt,
        ?DateTimeImmutable $decidedAt,
    ): self {
        return new self($id, $subjectType, $subjectId, $kind, $details, $status, $decidedBy, $note, $submittedAt, $decidedAt);
    }

    public function approve(string $adminId, ?string $note, DateTimeImmutable $now): void
    {
        $this->decide(ApprovalStatus::Approved, $adminId, $note, $now);
    }

    public function reject(string $adminId, ?string $note, DateTimeImmutable $now): void
    {
        $this->decide(ApprovalStatus::Rejected, $adminId, $note, $now);
    }

    private function decide(ApprovalStatus $status, string $adminId, ?string $note, DateTimeImmutable $now): void
    {
        if ($this->status !== ApprovalStatus::Pending) {
            throw new AdminInvalidState('This request has already been decided.');
        }
        $this->status = $status;
        $this->decidedBy = $adminId;
        $this->note = $note;
        $this->decidedAt = $now;
    }

    public function isApproved(): bool
    {
        return $this->status === ApprovalStatus::Approved;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function subjectType(): string
    {
        return $this->subjectType;
    }

    public function subjectId(): string
    {
        return $this->subjectId;
    }

    public function kind(): ApprovalKind
    {
        return $this->kind;
    }

    /** @return array<string, scalar|null> */
    public function details(): array
    {
        return $this->details;
    }

    public function status(): ApprovalStatus
    {
        return $this->status;
    }

    public function decidedBy(): ?string
    {
        return $this->decidedBy;
    }

    public function note(): ?string
    {
        return $this->note;
    }

    public function submittedAt(): DateTimeImmutable
    {
        return $this->submittedAt;
    }

    public function decidedAt(): ?DateTimeImmutable
    {
        return $this->decidedAt;
    }
}
