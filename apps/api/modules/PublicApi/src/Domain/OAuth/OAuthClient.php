<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Domain\OAuth;

use DateTimeImmutable;
use EruoFood\PublicApi\Domain\Enum\OAuthGrant;
use EruoFood\PublicApi\Domain\ValueObject\ScopeSet;

/**
 * An OAuth2 client registered against a developer application. It declares which
 * grant types it may use and which redirect URIs are legitimate, and it bounds
 * the scopes any token issued to it may carry (never wider than the application
 * was granted). A public client (e.g. a mobile app) holds no secret and must use
 * Authorization Code + PKCE; a confidential client authenticates with its secret.
 *
 * This aggregate is pure policy — it never issues tokens or touches persistence;
 * the {@see \EruoFood\PublicApi\Application\Service\OAuthService} orchestrates
 * issuance and the repositories persist. Scopes remain the single authorization
 * currency, identical to the API-key path, so BOLA/scope checks are unchanged.
 */
final class OAuthClient
{
    /**
     * @param list<OAuthGrant> $grants
     * @param list<string>     $redirectUris
     */
    private function __construct(
        private readonly string $id,
        private readonly string $applicationId,
        private readonly string $developerId,
        private string $name,
        private readonly ?string $hashedSecret,
        private readonly bool $confidential,
        private array $grants,
        private array $redirectUris,
        private ScopeSet $allowedScopes,
        private readonly DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * @param list<OAuthGrant> $grants
     * @param list<string>     $redirectUris
     */
    public static function register(
        string $id,
        string $applicationId,
        string $developerId,
        string $name,
        ?string $hashedSecret,
        bool $confidential,
        array $grants,
        array $redirectUris,
        ScopeSet $allowedScopes,
        DateTimeImmutable $now,
    ): self {
        return new self(
            $id, $applicationId, $developerId, $name, $hashedSecret, $confidential,
            array_values($grants), array_values($redirectUris), $allowedScopes, $now,
        );
    }

    /**
     * @param list<OAuthGrant> $grants
     * @param list<string>     $redirectUris
     */
    public static function reconstitute(
        string $id,
        string $applicationId,
        string $developerId,
        string $name,
        ?string $hashedSecret,
        bool $confidential,
        array $grants,
        array $redirectUris,
        ScopeSet $allowedScopes,
        DateTimeImmutable $createdAt,
    ): self {
        return new self(
            $id, $applicationId, $developerId, $name, $hashedSecret, $confidential,
            array_values($grants), array_values($redirectUris), $allowedScopes, $createdAt,
        );
    }

    public function supportsGrant(OAuthGrant $grant): bool
    {
        return in_array($grant, $this->grants, true);
    }

    public function redirectUriRegistered(string $uri): bool
    {
        return in_array($uri, $this->redirectUris, true);
    }

    /** The effective scopes for a request: the requested set clamped to what the client may hold. */
    public function scopesFor(ScopeSet $requested): ScopeSet
    {
        return $requested->isEmpty() ? $this->allowedScopes : $requested->intersect($this->allowedScopes);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function applicationId(): string
    {
        return $this->applicationId;
    }

    public function developerId(): string
    {
        return $this->developerId;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function hashedSecret(): ?string
    {
        return $this->hashedSecret;
    }

    public function isConfidential(): bool
    {
        return $this->confidential;
    }

    /** @return list<OAuthGrant> */
    public function grants(): array
    {
        return $this->grants;
    }

    /** @return list<string> */
    public function redirectUris(): array
    {
        return $this->redirectUris;
    }

    public function allowedScopes(): ScopeSet
    {
        return $this->allowedScopes;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
