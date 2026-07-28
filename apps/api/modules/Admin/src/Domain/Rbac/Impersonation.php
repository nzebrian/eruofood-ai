<?php

declare(strict_types=1);

namespace EruoFood\Admin\Domain\Rbac;

use DateTimeImmutable;

/**
 * A record that an admin is (or was) acting as another user. Every impersonation
 * is opened with a reason and closed explicitly, and both transitions are
 * audit-logged, so acting-as is always traceable.
 */
final class Impersonation
{
    private function __construct(
        private readonly string $id,
        private readonly string $adminUserId,
        private readonly string $targetUserId,
        private readonly string $reason,
        private readonly DateTimeImmutable $startedAt,
        private ?DateTimeImmutable $endedAt,
    ) {
    }

    public static function start(string $id, string $adminUserId, string $targetUserId, string $reason, DateTimeImmutable $now): self
    {
        return new self($id, $adminUserId, $targetUserId, $reason, $now, null);
    }

    public static function reconstitute(
        string $id,
        string $adminUserId,
        string $targetUserId,
        string $reason,
        DateTimeImmutable $startedAt,
        ?DateTimeImmutable $endedAt,
    ): self {
        return new self($id, $adminUserId, $targetUserId, $reason, $startedAt, $endedAt);
    }

    public function end(DateTimeImmutable $at): void
    {
        $this->endedAt ??= $at;
    }

    public function isActive(): bool
    {
        return $this->endedAt === null;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function adminUserId(): string
    {
        return $this->adminUserId;
    }

    public function targetUserId(): string
    {
        return $this->targetUserId;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function startedAt(): DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function endedAt(): ?DateTimeImmutable
    {
        return $this->endedAt;
    }
}
