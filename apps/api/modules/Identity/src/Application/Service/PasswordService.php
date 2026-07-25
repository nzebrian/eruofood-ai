<?php

declare(strict_types=1);

namespace EruoFood\Identity\Application\Service;

use EruoFood\Identity\Application\Port\AuditRecorder;
use EruoFood\Identity\Application\Port\AuthNotifier;
use EruoFood\Identity\Application\Port\OneTimeTokens;
use EruoFood\Identity\Application\Port\PasswordHasher;
use EruoFood\Identity\Application\Port\RefreshTokenManager;
use EruoFood\Identity\Domain\Exception\InvalidCredentials;
use EruoFood\Identity\Domain\Exception\UserNotFound;
use EruoFood\Identity\Domain\User\UserRepository;
use EruoFood\Identity\Domain\ValueObject\Email;
use EruoFood\Identity\Domain\ValueObject\UserId;

/** Use cases: forgot password (request), reset password, change password. */
final readonly class PasswordService
{
    public const PURPOSE_PASSWORD_RESET = 'password_reset';

    public function __construct(
        private UserRepository $users,
        private PasswordHasher $hasher,
        private OneTimeTokens $tokens,
        private AuthNotifier $notifier,
        private RefreshTokenManager $refreshTokens,
        private AuditRecorder $audit,
        private int $resetTtl,
    ) {
    }

    /**
     * Issue a reset link. To avoid account enumeration this method behaves
     * identically whether or not the email exists.
     */
    public function requestReset(string $email): void
    {
        $emailVo = new Email($email);
        $user = $this->users->findByEmail($emailVo);

        if ($user !== null) {
            $token = $this->tokens->issue(self::PURPOSE_PASSWORD_RESET, $emailVo->value, $this->resetTtl);
            $this->notifier->sendPasswordReset($emailVo, $token);
        }
    }

    public function reset(string $email, string $token, string $newPassword): void
    {
        $emailVo = new Email($email);

        if (! $this->tokens->consume(self::PURPOSE_PASSWORD_RESET, $emailVo->value, $token)) {
            throw new InvalidCredentials();
        }

        $user = $this->users->findByEmail($emailVo) ?? throw new InvalidCredentials();
        $user->changePassword($this->hasher->hash($newPassword));
        $this->users->save($user);

        // Reset invalidates every existing session.
        $this->refreshTokens->revokeAllForUser($user->id());
        $this->audit->record('auth.password_reset', $user->id());
    }

    public function change(string $userId, string $currentPassword, string $newPassword): void
    {
        $id = new UserId($userId);
        $user = $this->users->findById($id) ?? throw UserNotFound::forId($userId);

        $current = $user->password();
        if ($current === null || ! $this->hasher->verify($currentPassword, $current)) {
            throw new InvalidCredentials();
        }

        $user->changePassword($this->hasher->hash($newPassword));
        $this->users->save($user);
        $this->audit->record('auth.password_changed', $id);
    }
}
