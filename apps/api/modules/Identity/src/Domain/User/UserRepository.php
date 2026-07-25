<?php

declare(strict_types=1);

namespace EruoFood\Identity\Domain\User;

use EruoFood\Identity\Domain\ValueObject\Email;
use EruoFood\Identity\Domain\ValueObject\UserId;

/**
 * Repository port for the User aggregate. Defined in the domain, implemented in
 * infrastructure (Repository Pattern + Dependency Inversion). The domain never
 * knows about Eloquent.
 */
interface UserRepository
{
    public function nextIdentity(): UserId;

    public function findById(UserId $id): ?User;

    public function findByEmail(Email $email): ?User;

    public function existsByEmail(Email $email): bool;

    /** Persist a new or existing aggregate (upsert) and dispatch its events. */
    public function save(User $user): void;

    /** Soft-delete the user. */
    public function delete(UserId $id): void;

    /**
     * Paginated listing for administration. Items are User aggregates.
     *
     * @return \EruoFood\Shared\Domain\Paginated<User>
     */
    public function paginate(int $page, int $perPage): \EruoFood\Shared\Domain\Paginated;
}
