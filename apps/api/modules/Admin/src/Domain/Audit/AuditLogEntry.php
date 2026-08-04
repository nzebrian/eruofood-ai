<?php

declare(strict_types=1);

namespace EruoFood\Admin\Domain\Audit;

use DateTimeImmutable;
use EruoFood\Admin\Domain\Enum\AuditCategory;

/**
 * An immutable, append-only audit record. Every privileged action in the
 * platform — a config change, a user suspension, an impersonation, a vendor
 * approval, a sensitive data access — is written here once and never mutated,
 * giving the compliance history its integrity.
 *
 * The actor and subject are referenced by id only (soft refs); the free-form
 * {@see context()} bag carries the human-readable "what changed" detail.
 */
final class AuditLogEntry
{
    /**
     * @param array<string, scalar|null> $context
     */
    private function __construct(
        private readonly string $id,
        private readonly ?string $actorId,
        private readonly AuditCategory $category,
        private readonly string $action,
        private readonly ?string $subjectType,
        private readonly ?string $subjectId,
        private readonly array $context,
        private readonly ?string $ip,
        private readonly DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * @param array<string, scalar|null> $context
     */
    public static function record(
        string $id,
        ?string $actorId,
        AuditCategory $category,
        string $action,
        ?string $subjectType,
        ?string $subjectId,
        array $context,
        ?string $ip,
        DateTimeImmutable $now,
    ): self {
        return new self($id, $actorId, $category, $action, $subjectType, $subjectId, $context, $ip, $now);
    }

    /**
     * @param array<string, scalar|null> $context
     */
    public static function reconstitute(
        string $id,
        ?string $actorId,
        AuditCategory $category,
        string $action,
        ?string $subjectType,
        ?string $subjectId,
        array $context,
        ?string $ip,
        DateTimeImmutable $createdAt,
    ): self {
        return new self($id, $actorId, $category, $action, $subjectType, $subjectId, $context, $ip, $createdAt);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function actorId(): ?string
    {
        return $this->actorId;
    }

    public function category(): AuditCategory
    {
        return $this->category;
    }

    public function action(): string
    {
        return $this->action;
    }

    public function subjectType(): ?string
    {
        return $this->subjectType;
    }

    public function subjectId(): ?string
    {
        return $this->subjectId;
    }

    /** @return array<string, scalar|null> */
    public function context(): array
    {
        return $this->context;
    }

    public function ip(): ?string
    {
        return $this->ip;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
