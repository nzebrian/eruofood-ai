<?php

declare(strict_types=1);

namespace EruoFood\Identity\Application\Port;

use EruoFood\Identity\Domain\User\User;
use EruoFood\Identity\Domain\ValueObject\Email;

/** Sends transactional auth emails. Implemented via Laravel Mail (queued). */
interface AuthNotifier
{
    public function sendEmailVerification(User $user, string $token): void;

    public function sendPasswordReset(Email $email, string $token): void;
}
