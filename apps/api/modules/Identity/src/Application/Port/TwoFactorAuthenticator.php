<?php

declare(strict_types=1);

namespace EruoFood\Identity\Application\Port;

use EruoFood\Identity\Domain\ValueObject\Email;

/** TOTP two-factor operations. Implemented with google2fa in infrastructure. */
interface TwoFactorAuthenticator
{
    public function generateSecret(): string;

    /** otpauth:// URI used to render an enrolment QR code. */
    public function provisioningUri(Email $email, string $secret): string;

    public function verify(string $secret, string $code): bool;

    /** @return list<string> one-time recovery codes */
    public function generateRecoveryCodes(int $count): array;
}
