<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Domain\Developer;

/** Persistence port for the {@see Developer} account. */
interface DeveloperRepository
{
    public function nextIdentity(): string;

    public function findById(string $id): ?Developer;

    public function findByUserId(string $userId): ?Developer;

    public function save(Developer $developer): void;
}
