<?php

declare(strict_types=1);

namespace EruoFood\Admin\Domain\Rbac;

/** Persistence port for {@see Impersonation}. */
interface ImpersonationRepository
{
    public function nextIdentity(): string;

    public function findById(string $id): ?Impersonation;

    public function activeForAdmin(string $adminUserId): ?Impersonation;

    public function save(Impersonation $impersonation): void;
}
