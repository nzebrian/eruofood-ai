<?php

declare(strict_types=1);

namespace EruoFood\Identity\Application\DTO;

final readonly class TwoFactorEnrollment
{
    /**
     * @param list<string> $recoveryCodes
     */
    public function __construct(
        public string $secret,
        public string $provisioningUri,
        public array $recoveryCodes,
    ) {
    }
}
