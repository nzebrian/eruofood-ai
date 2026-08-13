<?php

declare(strict_types=1);

namespace EruoFood\Verification\Infrastructure\Security;

use EruoFood\Verification\Application\Port\PhoneVerificationSender;
use Psr\Log\LoggerInterface;

/**
 * The default phone-code sender: records that a code was dispatched, never the
 * code itself or the number in full.
 *
 * A real SMS provider replaces this in M-later without touching the service that
 * issues codes. The redaction here is the point — a verification code in an
 * application log is a bypass of the verification.
 */
final readonly class NullPhoneVerificationSender implements PhoneVerificationSender
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public function send(string $phoneNumber, string $code): void
    {
        $this->logger->info('Phone verification code dispatched.', [
            'phone' => $this->mask($phoneNumber),
            // Deliberately absent: the code.
        ]);
    }

    private function mask(string $phoneNumber): string
    {
        $digits = preg_replace('/\D+/', '', $phoneNumber) ?? '';

        return $digits === '' ? '***' : str_repeat('*', max(0, strlen($digits) - 3)).substr($digits, -3);
    }
}
