<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Domain\Broadcast;

use EruoFood\Shared\Domain\Paginated;

/** Persistence port for {@see Broadcast}. */
interface BroadcastRepository
{
    public function nextIdentity(): string;

    public function findById(string $id): ?Broadcast;

    /** @return Paginated<Broadcast> */
    public function all(int $page, int $perPage): Paginated;

    public function save(Broadcast $broadcast): void;
}
