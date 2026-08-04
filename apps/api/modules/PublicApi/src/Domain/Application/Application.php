<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Domain\Application;

use DateTimeImmutable;
use EruoFood\PublicApi\Domain\Enum\ApplicationStatus;
use EruoFood\PublicApi\Domain\Exception\PublicApiForbidden;
use EruoFood\PublicApi\Domain\ValueObject\ScopeSet;

/**
 * A developer's API application (client). It is the grant boundary: an
 * application is granted a set of scopes, and every API key it issues can only
 * ever hold a subset of those scopes. Suspending an application is the kill
 * switch for all its keys.
 */
final class Application
{
    private function __construct(
        private readonly string $id,
        private readonly string $developerId,
        private string $name,
        private string $description,
        private ScopeSet $scopes,
        private ApplicationStatus $status,
        private readonly DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt,
    ) {
    }

    public static function create(
        string $id,
        string $developerId,
        string $name,
        string $description,
        ScopeSet $scopes,
        DateTimeImmutable $now,
    ): self {
        return new self($id, $developerId, $name, $description, $scopes, ApplicationStatus::Active, $now, $now);
    }

    public static function reconstitute(
        string $id,
        string $developerId,
        string $name,
        string $description,
        ScopeSet $scopes,
        ApplicationStatus $status,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
    ): self {
        return new self($id, $developerId, $name, $description, $scopes, $status, $createdAt, $updatedAt);
    }

    /** Grant/replace the application's scopes (the ceiling for its keys). */
    public function setScopes(ScopeSet $scopes, DateTimeImmutable $now): void
    {
        $this->scopes = $scopes;
        $this->updatedAt = $now;
    }

    public function suspend(DateTimeImmutable $now): void
    {
        $this->status = ApplicationStatus::Suspended;
        $this->updatedAt = $now;
    }

    public function activate(DateTimeImmutable $now): void
    {
        $this->status = ApplicationStatus::Active;
        $this->updatedAt = $now;
    }

    public function isOwnedBy(string $developerId): void
    {
        if ($this->developerId !== $developerId) {
            throw new PublicApiForbidden('This application belongs to another developer.');
        }
    }

    public function id(): string
    {
        return $this->id;
    }

    public function developerId(): string
    {
        return $this->developerId;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function scopes(): ScopeSet
    {
        return $this->scopes;
    }

    public function status(): ApplicationStatus
    {
        return $this->status;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
