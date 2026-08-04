<?php

declare(strict_types=1);

namespace EruoFood\Identity\Application\Port;

use EruoFood\Identity\Application\DTO\IssuedRefreshToken;
use EruoFood\Identity\Application\DTO\SessionMetadata;
use EruoFood\Identity\Domain\ValueObject\UserId;

/**
 * Manages opaque refresh tokens. Each refresh token represents a session/device
 * (Session Management). Tokens are stored hashed and rotated on use.
 */
interface RefreshTokenManager
{
    public function issue(UserId $userId, SessionMetadata $meta): IssuedRefreshToken;

    /** Resolve the owning user of a valid, non-revoked, non-expired token. */
    public function resolveUser(string $plaintext): ?UserId;

    /** Rotate: revoke the presented token and issue a fresh one for the session. */
    public function rotate(string $plaintext, SessionMetadata $meta): ?IssuedRefreshToken;

    public function revoke(string $plaintext): void;

    public function revokeSession(UserId $userId, string $sessionId): void;

    public function revokeAllForUser(UserId $userId): void;

    /** @return list<\EruoFood\Identity\Application\DTO\SessionView> */
    public function listSessions(UserId $userId): array;
}
