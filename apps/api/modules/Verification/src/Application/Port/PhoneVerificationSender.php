<?php

declare(strict_types=1);

namespace EruoFood\Verification\Application\Port;

/**
 * Delivers a phone verification code.
 *
 * A port because SMS delivery is a provider concern the domain should not know
 * about, and because tests need a null implementation that never sends.
 */
interface PhoneVerificationSender
{
    public function send(string $phoneNumber, string $code): void;
}
