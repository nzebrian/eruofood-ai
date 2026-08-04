<?php

declare(strict_types=1);

namespace EruoFood\Admin\Application\DTO;

use DateTimeImmutable;

/**
 * A read-only projection of an Identity user, as seen by the admin User
 * Administration screens. Assembled by the {@see \EruoFood\Admin\Application\Port\UserDirectory}
 * adapter from Identity's own data — Admin never joins Identity's tables.
 */
final readonly class UserSummary
{
    public function __construct(
        public string $id,
        public string $name,
        public string $email,
        public string $status,
        public bool $verified,
        public ?DateTimeImmutable $registeredAt,
    ) {
    }
}
