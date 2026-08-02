<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Application\Service;

use DateTimeImmutable;
use EruoFood\PublicApi\Domain\Developer\Developer;
use EruoFood\PublicApi\Domain\Developer\DeveloperRepository;
use EruoFood\PublicApi\Domain\Exception\PublicApiNotFound;

/**
 * Developer accounts. A platform user becomes a developer on first access to the
 * portal; the account links their user id to their applications without coupling
 * to the Identity context beyond that id.
 */
final readonly class DeveloperService
{
    public function __construct(private DeveloperRepository $developers)
    {
    }

    public function registerFor(string $userId, string $name, string $email): Developer
    {
        $existing = $this->developers->findByUserId($userId);
        if ($existing !== null) {
            return $existing;
        }
        $developer = Developer::register($this->developers->nextIdentity(), $userId, $name, $email, new DateTimeImmutable());
        $this->developers->save($developer);

        return $developer;
    }

    public function forUser(string $userId): Developer
    {
        return $this->developers->findByUserId($userId)
            ?? throw PublicApiNotFound::of('developer', $userId);
    }
}
