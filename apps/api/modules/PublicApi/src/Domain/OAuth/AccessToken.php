<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Domain\OAuth;

use DateTimeImmutable;
use EruoFood\PublicApi\Domain\ValueObject\ScopeSet;

/**
 * A bearer access token. Only its hash is stored. It carries the same
 * authorization currency as an API key — an application id, developer id,
 * granted scopes, and (for user-delegated tokens) the subject user id that
 * drives object-level authorization. A client-credentials token has no subject
 * and therefore, exactly like an application-level API key, cannot reach
 * customer-owned resources.
 */
final class AccessToken
{
    private function __construct(
        private readonly string $id,
        private readonly string $hashedToken,
        private readonly string $clientId,
        private readonly string $applicationId,
        private readonly string $developerId,
        private readonly ?string $subjectUserId,
        private readonly ScopeSet $scopes,
        private readonly DateTimeImmutable $expiresAt,
        private ?DateTimeImmutable $revokedAt,
        private readonly DateTimeImmutable $createdAt,
    ) {
    }

    public static function issue(
        string $id,
        string $hashedToken,
        string $clientId,
        string $applicationId,
        string $developerId,
        ?string $subjectUserId,
        ScopeSet $scopes,
        DateTimeImmutable $expiresAt,
        DateTimeImmutable $now,
    ): self {
        return new self($id, $hashedToken, $clientId, $applicationId, $developerId, $subjectUserId, $scopes, $expiresAt, null, $now);
    }

    public static function reconstitute(
        string $id,
        string $hashedToken,
        string $clientId,
        string $applicationId,
        string $developerId,
        ?string $subjectUserId,
        ScopeSet $scopes,
        DateTimeImmutable $expiresAt,
        ?DateTimeImmutable $revokedAt,
        DateTimeImmutable $createdAt,
    ): self {
        return new self($id, $hashedToken, $clientId, $applicationId, $developerId, $subjectUserId, $scopes, $expiresAt, $revokedAt, $createdAt);
    }

    public function isValid(DateTimeImmutable $now): bool
    {
        return $this->revokedAt === null && $now < $this->expiresAt;
    }

    public function revoke(DateTimeImmutable $now): void
    {
        $this->revokedAt ??= $now;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function hashedToken(): string
    {
        return $this->hashedToken;
    }

    public function clientId(): string
    {
        return $this->clientId;
    }

    public function applicationId(): string
    {
        return $this->applicationId;
    }

    public function developerId(): string
    {
        return $this->developerId;
    }

    public function subjectUserId(): ?string
    {
        return $this->subjectUserId;
    }

    public function scopes(): ScopeSet
    {
        return $this->scopes;
    }

    public function expiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function revokedAt(): ?DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
