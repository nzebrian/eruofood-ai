<?php

declare(strict_types=1);

namespace EruoFood\Identity\Application\Service;

use EruoFood\Identity\Application\Port\AuditRecorder;
use EruoFood\Identity\Application\Port\RefreshTokenManager;
use EruoFood\Identity\Domain\ValueObject\UserId;

/** Session management: list active sessions and revoke a specific one. */
final readonly class SessionService
{
    public function __construct(
        private RefreshTokenManager $refreshTokens,
        private AuditRecorder $audit,
    ) {
    }

    /** @return list<\EruoFood\Identity\Application\DTO\SessionView> */
    public function listSessions(string $userId): array
    {
        return $this->refreshTokens->listSessions(new UserId($userId));
    }

    public function revokeSession(string $userId, string $sessionId): void
    {
        $id = new UserId($userId);
        $this->refreshTokens->revokeSession($id, $sessionId);
        $this->audit->record('auth.session_revoked', $id, ['session' => $sessionId]);
    }

    public function revokeAll(string $userId): void
    {
        $this->refreshTokens->revokeAllForUser(new UserId($userId));
    }
}
