<?php

declare(strict_types=1);

namespace EruoFood\Identity\Infrastructure\Auth;

use EruoFood\Identity\Application\Port\TwoFactorAuthenticator;
use EruoFood\Identity\Domain\ValueObject\Email;
use PragmaRX\Google2FA\Google2FA;

/** TOTP two-factor via pragmarx/google2fa. */
final readonly class Google2FaAuthenticator implements TwoFactorAuthenticator
{
    public function __construct(
        private Google2FA $engine,
        private string $issuer,
        private int $window,
    ) {
    }

    public function generateSecret(): string
    {
        return $this->engine->generateSecretKey();
    }

    public function provisioningUri(Email $email, string $secret): string
    {
        return $this->engine->getQRCodeUrl($this->issuer, $email->value, $secret);
    }

    public function verify(string $secret, string $code): bool
    {
        return $this->engine->verifyKey($secret, $code, $this->window);
    }

    public function generateRecoveryCodes(int $count): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = sprintf(
                '%s-%s',
                strtoupper(bin2hex(random_bytes(4))),
                strtoupper(bin2hex(random_bytes(4))),
            );
        }

        return $codes;
    }
}
