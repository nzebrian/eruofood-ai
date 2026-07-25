<?php

declare(strict_types=1);

namespace EruoFood\Identity\Application\Service;

use EruoFood\Identity\Application\DTO\TwoFactorEnrollment;
use EruoFood\Identity\Application\Port\AuditRecorder;
use EruoFood\Identity\Application\Port\PasswordHasher;
use EruoFood\Identity\Application\Port\TwoFactorAuthenticator;
use EruoFood\Identity\Domain\Exception\InvalidCredentials;
use EruoFood\Identity\Domain\Exception\InvalidTwoFactorCode;
use EruoFood\Identity\Domain\Exception\UserNotFound;
use EruoFood\Identity\Domain\User\UserRepository;
use EruoFood\Identity\Domain\ValueObject\UserId;

/** Use cases: begin 2FA enrolment, confirm it, and disable it. */
final readonly class TwoFactorService
{
    public function __construct(
        private UserRepository $users,
        private TwoFactorAuthenticator $twoFactor,
        private PasswordHasher $hasher,
        private AuditRecorder $audit,
        private int $recoveryCodeCount,
    ) {
    }

    /** Generate a secret + recovery codes and store them pending confirmation. */
    public function enable(string $userId): TwoFactorEnrollment
    {
        $user = $this->users->findById(new UserId($userId)) ?? throw UserNotFound::forId($userId);

        $secret = $this->twoFactor->generateSecret();
        $recoveryCodes = $this->twoFactor->generateRecoveryCodes($this->recoveryCodeCount);

        $user->enableTwoFactor($secret, $recoveryCodes);
        $this->users->save($user);

        return new TwoFactorEnrollment(
            secret: $secret,
            provisioningUri: $this->twoFactor->provisioningUri($user->email(), $secret),
            recoveryCodes: $recoveryCodes,
        );
    }

    /** Confirm enrolment by verifying the first TOTP code. */
    public function confirm(string $userId, string $code): void
    {
        $id = new UserId($userId);
        $user = $this->users->findById($id) ?? throw UserNotFound::forId($userId);

        $secret = $user->twoFactor()->secret;
        if ($secret === null || ! $this->twoFactor->verify($secret, $code)) {
            throw new InvalidTwoFactorCode();
        }

        $user->confirmTwoFactor();
        $this->users->save($user);
        $this->audit->record('auth.two_factor_enabled', $id);
    }

    /** Disable 2FA after re-verifying the account password. */
    public function disable(string $userId, string $password): void
    {
        $id = new UserId($userId);
        $user = $this->users->findById($id) ?? throw UserNotFound::forId($userId);

        $current = $user->password();
        if ($current === null || ! $this->hasher->verify($password, $current)) {
            throw new InvalidCredentials();
        }

        $user->disableTwoFactor();
        $this->users->save($user);
        $this->audit->record('auth.two_factor_disabled', $id);
    }
}
