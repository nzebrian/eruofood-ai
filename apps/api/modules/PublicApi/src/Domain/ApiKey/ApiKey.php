<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Domain\ApiKey;

use DateTimeImmutable;
use EruoFood\PublicApi\Domain\Enum\ApiKeyStatus;
use EruoFood\PublicApi\Domain\ValueObject\Scope;
use EruoFood\PublicApi\Domain\ValueObject\ScopeSet;

/**
 * An API key credential. The plaintext secret is NEVER stored — only its hash
 * and the public `prefix` (the lookup id, safe to display). A key carries its
 * own scope set (a subset of its application's grants) and may expire. All
 * authentication decisions run through {@see isUsable()} + {@see grants()}.
 */
final class ApiKey
{
    private function __construct(
        private readonly string $id,
        private readonly string $applicationId,
        private string $name,
        private readonly string $prefix,
        private readonly string $hashedSecret,
        private ScopeSet $scopes,
        private ApiKeyStatus $status,
        private readonly ?DateTimeImmutable $expiresAt,
        private ?DateTimeImmutable $lastUsedAt,
        private readonly DateTimeImmutable $createdAt,
        private ?DateTimeImmutable $revokedAt,
        private readonly ?string $subjectUserId = null,
    ) {
    }

    public static function issue(
        string $id,
        string $applicationId,
        string $name,
        string $prefix,
        string $hashedSecret,
        ScopeSet $scopes,
        ?DateTimeImmutable $expiresAt,
        DateTimeImmutable $now,
        ?string $subjectUserId = null,
    ): self {
        return new self($id, $applicationId, $name, $prefix, $hashedSecret, $scopes, ApiKeyStatus::Active, $expiresAt, null, $now, null, $subjectUserId);
    }

    public static function reconstitute(
        string $id,
        string $applicationId,
        string $name,
        string $prefix,
        string $hashedSecret,
        ScopeSet $scopes,
        ApiKeyStatus $status,
        ?DateTimeImmutable $expiresAt,
        ?DateTimeImmutable $lastUsedAt,
        DateTimeImmutable $createdAt,
        ?DateTimeImmutable $revokedAt,
        ?string $subjectUserId = null,
    ): self {
        return new self($id, $applicationId, $name, $prefix, $hashedSecret, $scopes, $status, $expiresAt, $lastUsedAt, $createdAt, $revokedAt, $subjectUserId);
    }

    /**
     * The customer this key acts on behalf of, if it is a customer-scoped key.
     * Null for application-level keys (which cannot access customer-owned
     * resources such as orders). This is the BOLA principal subject.
     */
    public function subjectUserId(): ?string
    {
        return $this->subjectUserId;
    }

    public function revoke(DateTimeImmutable $now): void
    {
        $this->status = ApiKeyStatus::Revoked;
        $this->revokedAt = $now;
    }

    public function isExpired(DateTimeImmutable $now): bool
    {
        return $this->expiresAt !== null && $this->expiresAt <= $now;
    }

    /** Whether the key may authenticate a request right now. */
    public function isUsable(DateTimeImmutable $now): bool
    {
        return $this->status === ApiKeyStatus::Active && ! $this->isExpired($now);
    }

    public function grants(Scope $scope): bool
    {
        return $this->scopes->grants($scope);
    }

    public function touch(DateTimeImmutable $now): void
    {
        $this->lastUsedAt = $now;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function applicationId(): string
    {
        return $this->applicationId;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    public function hashedSecret(): string
    {
        return $this->hashedSecret;
    }

    public function scopes(): ScopeSet
    {
        return $this->scopes;
    }

    public function status(): ApiKeyStatus
    {
        return $this->status;
    }

    public function expiresAt(): ?DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function lastUsedAt(): ?DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function revokedAt(): ?DateTimeImmutable
    {
        return $this->revokedAt;
    }
}
