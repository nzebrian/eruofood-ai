<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Domain\OAuth;

use DateTimeImmutable;
use EruoFood\PublicApi\Domain\ValueObject\ScopeSet;

/**
 * A short-lived, single-use authorization code minted after a resource owner
 * consents. It is bound to the requesting client, the exact redirect URI, the
 * consenting user (the BOLA subject), the granted scopes, and a PKCE challenge.
 * The plaintext code is never stored — only its hash. Redemption verifies the
 * PKCE `code_verifier` against the stored challenge, so an intercepted code is
 * useless without the verifier held only by the legitimate client.
 */
final class AuthorizationCode
{
    private function __construct(
        private readonly string $id,
        private readonly string $hashedCode,
        private readonly string $clientId,
        private readonly string $subjectUserId,
        private readonly string $redirectUri,
        private readonly ScopeSet $scopes,
        private readonly string $codeChallenge,
        private readonly string $codeChallengeMethod,
        private readonly DateTimeImmutable $expiresAt,
        private ?DateTimeImmutable $consumedAt,
    ) {
    }

    public static function issue(
        string $id,
        string $hashedCode,
        string $clientId,
        string $subjectUserId,
        string $redirectUri,
        ScopeSet $scopes,
        string $codeChallenge,
        string $codeChallengeMethod,
        DateTimeImmutable $expiresAt,
    ): self {
        return new self(
            $id, $hashedCode, $clientId, $subjectUserId, $redirectUri, $scopes,
            $codeChallenge, $codeChallengeMethod, $expiresAt, null,
        );
    }

    public static function reconstitute(
        string $id,
        string $hashedCode,
        string $clientId,
        string $subjectUserId,
        string $redirectUri,
        ScopeSet $scopes,
        string $codeChallenge,
        string $codeChallengeMethod,
        DateTimeImmutable $expiresAt,
        ?DateTimeImmutable $consumedAt,
    ): self {
        return new self(
            $id, $hashedCode, $clientId, $subjectUserId, $redirectUri, $scopes,
            $codeChallenge, $codeChallengeMethod, $expiresAt, $consumedAt,
        );
    }

    public function isUsable(DateTimeImmutable $now): bool
    {
        return $this->consumedAt === null && $now < $this->expiresAt;
    }

    public function consume(DateTimeImmutable $now): void
    {
        $this->consumedAt = $now;
    }

    /**
     * Verify a PKCE `code_verifier` against the stored challenge. `S256` compares
     * the base64url-encoded SHA-256 of the verifier; `plain` compares directly.
     */
    public function verifyChallenge(string $codeVerifier): bool
    {
        $expected = match ($this->codeChallengeMethod) {
            'S256' => rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '='),
            default => $codeVerifier, // 'plain'
        };

        return hash_equals($this->codeChallenge, $expected);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function hashedCode(): string
    {
        return $this->hashedCode;
    }

    public function clientId(): string
    {
        return $this->clientId;
    }

    public function subjectUserId(): string
    {
        return $this->subjectUserId;
    }

    public function redirectUri(): string
    {
        return $this->redirectUri;
    }

    public function scopes(): ScopeSet
    {
        return $this->scopes;
    }

    public function codeChallenge(): string
    {
        return $this->codeChallenge;
    }

    public function codeChallengeMethod(): string
    {
        return $this->codeChallengeMethod;
    }

    public function expiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function consumedAt(): ?DateTimeImmutable
    {
        return $this->consumedAt;
    }
}
