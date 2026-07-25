<?php

declare(strict_types=1);

namespace EruoFood\Identity\Infrastructure\Auth;

use EruoFood\Identity\Application\DTO\AccessToken;
use EruoFood\Identity\Application\DTO\TokenClaims;
use EruoFood\Identity\Application\Port\TokenIssuer;
use EruoFood\Identity\Domain\Role\Role;
use EruoFood\Identity\Domain\User\User;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Throwable;

/**
 * JWT access-token issuer/parser (firebase/php-jwt). Access tokens are
 * short-lived and stateless; verification here backs the JwtAuthenticate
 * middleware. Signing details never leak into the application/domain layers.
 */
final readonly class JwtTokenIssuer implements TokenIssuer
{
    public function __construct(
        private string $secret,
        private string $algo,
        private string $issuer,
        private string $audience,
        private int $ttlMinutes,
    ) {
    }

    public function issue(User $user): AccessToken
    {
        $now = time();
        $expiresIn = $this->ttlMinutes * 60;

        $payload = [
            'iss' => $this->issuer,
            'aud' => $this->audience,
            'sub' => $user->id()->value(),
            'iat' => $now,
            'exp' => $now + $expiresIn,
            'jti' => bin2hex(random_bytes(16)),
            'roles' => array_map(static fn (Role $r): string => $r->value, $user->roles()),
        ];

        $token = JWT::encode($payload, $this->secret, $this->algo);

        return new AccessToken($token, $expiresIn);
    }

    public function parse(string $token): ?TokenClaims
    {
        try {
            $decoded = JWT::decode($token, new Key($this->secret, $this->algo));
        } catch (Throwable) {
            return null;
        }

        /** @var list<string> $roles */
        $roles = isset($decoded->roles) ? (array) $decoded->roles : [];

        return new TokenClaims((string) $decoded->sub, array_values($roles));
    }
}
