<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Domain\OAuth;

use DateTimeImmutable;
use EruoFood\PublicApi\Domain\ValueObject\ScopeSet;

/**
 * A refresh token, paired with the access token it was issued alongside. Only
 * its hash is stored. Refresh tokens are rotated on use: redeeming one revokes
 * it and issues a fresh pair, so a leaked-and-reused token is detectable and the
 * old one is already dead. It never widens scopes — a refresh may only request a
 * subset of what was originally granted.
 */
final class RefreshToken
{
    private function __construct(
        private readonly string $id,
        private readonly string $hashedToken,
        private readonly string $accessTokenId,
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
        string $accessTokenId,
        string $clientId,
        string $applicationId,
        string $developerId,
        ?string $subjectUserId,
        ScopeSet $scopes,
        DateTimeImmutable $expiresAt,
        DateTimeImmutable $now,
    ): self {
        return new self($id, $hashedToken, $accessTokenId, $clientId, $applicationId, $developerId, $subjectUserId, $scopes, $expiresAt, null, $now);
    }

    public static function reconstitute(
        string $id,
        string $hashedToken,
        string $accessTokenId,
        string $clientId,
        string $applicationId,
        string $developerId,
        ?string $subjectUserId,
        ScopeSet $scopes,
        DateTimeImmutable $expiresAt,
        ?DateTimeImmutable $revokedAt,
        DateTimeImmutable $createdAt,
    ): self {
        return new self($id, $hashedToken, $accessTokenId, $clientId, $applicationId, $developerId, $subjectUserId, $scopes, $expiresAt, $revokedAt, $createdAt);
    }

    public function isUsable(DateTimeImmutable $now): bool
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

    public function accessTokenId(): string
    {
        return $this->accessTokenId;
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
