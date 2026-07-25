<?php

declare(strict_types=1);

namespace EruoFood\Identity\Infrastructure\Auth\Social;

use EruoFood\Identity\Application\DTO\SocialIdentity;
use EruoFood\Identity\Application\Port\SocialAuthenticator;
use EruoFood\Identity\Domain\Exception\InvalidCredentials;
use Illuminate\Http\Client\Factory as HttpClient;

/**
 * Verifies a Google ID token via Google's tokeninfo endpoint and returns the
 * normalised identity. The audience (client id) is checked to ensure the token
 * was minted for this application.
 */
final readonly class GoogleAuthenticator implements SocialAuthenticator
{
    public function __construct(
        private HttpClient $http,
        private ?string $clientId,
        private bool $enabled,
    ) {
    }

    public function provider(): string
    {
        return 'google';
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function verify(string $idToken): SocialIdentity
    {
        $response = $this->http->get('https://oauth2.googleapis.com/tokeninfo', ['id_token' => $idToken]);

        if ($response->failed()) {
            throw new InvalidCredentials();
        }

        /** @var array<string, mixed> $data */
        $data = $response->json();

        if ($this->clientId !== null && ($data['aud'] ?? null) !== $this->clientId) {
            throw new InvalidCredentials();
        }

        if (! isset($data['sub'], $data['email'])) {
            throw new InvalidCredentials();
        }

        $verified = $data['email_verified'] ?? false;

        return new SocialIdentity(
            provider: 'google',
            providerUserId: (string) $data['sub'],
            email: (string) $data['email'],
            name: isset($data['name']) ? (string) $data['name'] : null,
            emailVerified: $verified === true || $verified === 'true',
        );
    }
}
