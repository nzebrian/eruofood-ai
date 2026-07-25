<?php

declare(strict_types=1);

namespace EruoFood\Identity\Domain\User;

/**
 * Two-factor authentication state for a user. Immutable — enabling, confirming,
 * or disabling 2FA produces a new instance. The secret and recovery codes are
 * encrypted at the persistence boundary, never here.
 */
final readonly class TwoFactorSettings
{
    /**
     * @param list<string> $recoveryCodes
     */
    public function __construct(
        public ?string $secret = null,
        public bool $confirmed = false,
        public array $recoveryCodes = [],
    ) {
    }

    public static function disabled(): self
    {
        return new self();
    }

    public function isEnabled(): bool
    {
        return $this->secret !== null && $this->confirmed;
    }

    public function isPending(): bool
    {
        return $this->secret !== null && ! $this->confirmed;
    }
}
