<?php

declare(strict_types=1);

namespace EruoFood\Identity\Infrastructure\Mail;

use EruoFood\Identity\Application\Port\AuthNotifier;
use EruoFood\Identity\Domain\User\User;
use EruoFood\Identity\Domain\ValueObject\Email;
use Illuminate\Contracts\Mail\Mailer;

/**
 * Sends auth emails by queuing Mailables. Links point at the frontend routes
 * that call the corresponding API endpoints.
 */
final readonly class LaravelAuthNotifier implements AuthNotifier
{
    public function __construct(
        private Mailer $mailer,
        private string $frontendUrl,
    ) {
    }

    public function sendEmailVerification(User $user, string $token): void
    {
        $url = sprintf(
            '%s/verify-email?uid=%s&token=%s',
            rtrim($this->frontendUrl, '/'),
            urlencode($user->id()->value()),
            urlencode($token),
        );

        $this->mailer->to($user->email()->value)->send(
            new VerifyEmailMail((string) $user->name(), $url),
        );
    }

    public function sendPasswordReset(Email $email, string $token): void
    {
        $url = sprintf(
            '%s/reset-password?email=%s&token=%s',
            rtrim($this->frontendUrl, '/'),
            urlencode($email->value),
            urlencode($token),
        );

        $this->mailer->to($email->value)->send(new ResetPasswordMail($url));
    }
}
