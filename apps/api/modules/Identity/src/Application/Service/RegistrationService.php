<?php

declare(strict_types=1);

namespace EruoFood\Identity\Application\Service;

use EruoFood\Identity\Application\DTO\AuthResult;
use EruoFood\Identity\Application\DTO\SessionMetadata;
use EruoFood\Identity\Application\DTO\UserProfileView;
use EruoFood\Identity\Application\Port\AuditRecorder;
use EruoFood\Identity\Application\Port\AuthNotifier;
use EruoFood\Identity\Application\Port\OneTimeTokens;
use EruoFood\Identity\Application\Port\PasswordHasher;
use EruoFood\Identity\Domain\Exception\EmailAlreadyRegistered;
use EruoFood\Identity\Domain\Exception\UserNotFound;
use EruoFood\Identity\Domain\User\User;
use EruoFood\Identity\Domain\User\UserRepository;
use EruoFood\Identity\Domain\ValueObject\Email;
use EruoFood\Identity\Domain\ValueObject\FullName;
use EruoFood\Identity\Domain\ValueObject\UserId;

/** Use cases: register, verify email, resend verification. */
final readonly class RegistrationService
{
    public const PURPOSE_EMAIL_VERIFICATION = 'email_verification';

    public function __construct(
        private UserRepository $users,
        private PasswordHasher $hasher,
        private OneTimeTokens $tokens,
        private AuthNotifier $notifier,
        private TokenService $tokenService,
        private AuditRecorder $audit,
        private int $emailVerificationTtl,
    ) {
    }

    public function register(string $name, string $email, string $password, SessionMetadata $meta): AuthResult
    {
        $emailVo = new Email($email);

        if ($this->users->existsByEmail($emailVo)) {
            throw new EmailAlreadyRegistered();
        }

        $user = User::register(
            id: $this->users->nextIdentity(),
            name: new FullName($name),
            email: $emailVo,
            password: $this->hasher->hash($password),
        );

        $this->users->save($user);
        $this->dispatchVerification($user);
        $this->audit->record('user.registered', $user->id(), ['email' => $emailVo->value]);

        $tokens = $this->tokenService->issueFor($user, $meta);

        return AuthResult::authenticated(UserProfileView::fromUser($user, null), $tokens);
    }

    public function verifyEmail(string $userId, string $token): void
    {
        $id = new UserId($userId);
        $user = $this->users->findById($id) ?? throw UserNotFound::forId($userId);

        if ($this->tokens->consume(self::PURPOSE_EMAIL_VERIFICATION, $userId, $token)) {
            $user->verifyEmail();
            $this->users->save($user);
            $this->audit->record('user.email_verified', $id);
        }
    }

    public function resendVerification(string $userId): void
    {
        $user = $this->users->findById(new UserId($userId)) ?? throw UserNotFound::forId($userId);

        if (! $user->hasVerifiedEmail()) {
            $this->dispatchVerification($user);
        }
    }

    private function dispatchVerification(User $user): void
    {
        $token = $this->tokens->issue(
            self::PURPOSE_EMAIL_VERIFICATION,
            $user->id()->value(),
            $this->emailVerificationTtl,
        );

        $this->notifier->sendEmailVerification($user, $token);
    }
}
