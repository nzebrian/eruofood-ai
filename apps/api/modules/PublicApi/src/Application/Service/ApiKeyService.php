<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Application\Service;

use DateTimeImmutable;
use EruoFood\PublicApi\Application\Port\SecretHasher;
use EruoFood\PublicApi\Domain\ApiKey\ApiKey;
use EruoFood\PublicApi\Domain\ApiKey\ApiKeyRepository;
use EruoFood\PublicApi\Domain\Application\ApplicationRepository;
use EruoFood\PublicApi\Domain\Event\ApiKeyIssued;
use EruoFood\PublicApi\Domain\Event\ApiKeyRevoked;
use EruoFood\PublicApi\Domain\Exception\PublicApiNotFound;
use EruoFood\PublicApi\Domain\ValueObject\ScopeSet;
use EruoFood\Shared\Domain\EventBus;

/**
 * Issues, rotates, revokes and authenticates API keys — the credential core of
 * the public API.
 *
 * Security invariants:
 *  - The plaintext secret is generated from a CSPRNG, returned exactly once, and
 *    never persisted; only its hash and the public prefix are stored.
 *  - A key's scopes are the intersection of the requested scopes and its
 *    application's grants — a key can never exceed its application.
 *  - Authentication verifies the hash in constant time and rejects revoked,
 *    expired keys and suspended applications.
 */
final readonly class ApiKeyService
{
    public function __construct(
        private ApiKeyRepository $keys,
        private ApplicationRepository $applications,
        private SecretHasher $hasher,
        private EventBus $events,
        private string $keyPrefix,
        private string $envTag,
        private int $secretBytes,
        private int $defaultTtlDays,
    ) {
    }

    /**
     * @param list<string> $requestedScopes
     */
    public function issue(string $applicationId, string $developerId, string $name, array $requestedScopes, ?int $ttlDays = null, ?string $subjectUserId = null): IssuedApiKey
    {
        $application = $this->applications->findById($applicationId) ?? throw PublicApiNotFound::of('application', $applicationId);
        $application->isOwnedBy($developerId);

        // Never widen beyond the application's grant.
        $scopes = (new ScopeSet(array_values(array_map('strval', $requestedScopes))))->intersect($application->scopes());

        $now = new DateTimeImmutable();
        $prefix = $this->generatePrefix();
        $secret = $this->generateSecret();
        $ttl = $ttlDays ?? ($this->defaultTtlDays > 0 ? $this->defaultTtlDays : null);
        $expiresAt = $ttl !== null && $ttl > 0 ? $now->modify(sprintf('+%d days', $ttl)) : null;

        $key = ApiKey::issue(
            $this->keys->nextIdentity(),
            $applicationId,
            $name,
            $prefix,
            $this->hasher->hash($secret),
            $scopes,
            $expiresAt,
            $now,
            $subjectUserId !== null && $subjectUserId !== '' ? $subjectUserId : null,
        );
        $this->keys->save($key);
        $this->events->publish(new ApiKeyIssued($key->id(), $applicationId));

        return new IssuedApiKey($key, $prefix.'.'.$secret);
    }

    public function rotate(string $keyId, string $developerId): IssuedApiKey
    {
        $key = $this->keys->findById($keyId) ?? throw PublicApiNotFound::of('api key', $keyId);
        $application = $this->applications->findById($key->applicationId()) ?? throw PublicApiNotFound::of('application', $key->applicationId());
        $application->isOwnedBy($developerId);

        $key->revoke(new DateTimeImmutable());
        $this->keys->save($key);

        return $this->issue($key->applicationId(), $developerId, $key->name(), $key->scopes()->toArray(), null, $key->subjectUserId());
    }

    public function revoke(string $keyId, string $developerId): void
    {
        $key = $this->keys->findById($keyId) ?? throw PublicApiNotFound::of('api key', $keyId);
        $application = $this->applications->findById($key->applicationId()) ?? throw PublicApiNotFound::of('application', $key->applicationId());
        $application->isOwnedBy($developerId);

        $key->revoke(new DateTimeImmutable());
        $this->keys->save($key);
        $this->events->publish(new ApiKeyRevoked($key->id(), $key->applicationId()));
    }

    /**
     * @return list<ApiKey>
     */
    public function forApplication(string $applicationId, string $developerId): array
    {
        $application = $this->applications->findById($applicationId) ?? throw PublicApiNotFound::of('application', $applicationId);
        $application->isOwnedBy($developerId);

        return $this->keys->forApplication($applicationId);
    }

    /**
     * Resolve a presented key ("prefix.secret") to an authenticated client, or
     * null if authentication fails for any reason (unknown, wrong secret,
     * revoked, expired, or the application is suspended).
     */
    public function authenticate(string $presented): ?AuthenticatedClient
    {
        $parts = explode('.', $presented, 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return null;
        }
        [$prefix, $secret] = $parts;

        $key = $this->keys->findByPrefix($prefix);
        if ($key === null) {
            return null;
        }
        if (! $this->hasher->verify($secret, $key->hashedSecret())) {
            return null;
        }
        $now = new DateTimeImmutable();
        if (! $key->isUsable($now)) {
            return null;
        }
        $application = $this->applications->findById($key->applicationId());
        if ($application === null || ! $application->status()->isActive()) {
            return null;
        }

        $key->touch($now);
        $this->keys->save($key);

        return new AuthenticatedClient($application, $key);
    }

    private function generatePrefix(): string
    {
        return sprintf('%s_%s_%s', $this->keyPrefix, $this->envTag, bin2hex(random_bytes(4)));
    }

    private function generateSecret(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(max(16, $this->secretBytes))), '+/', '-_'), '=');
    }
}
