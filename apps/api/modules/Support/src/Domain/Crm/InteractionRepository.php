<?php

declare(strict_types=1);

namespace EruoFood\Support\Domain\Crm;

use EruoFood\Shared\Domain\Paginated;

/** Append-only persistence port for the customer timeline. */
interface InteractionRepository
{
    public function nextIdentity(): string;

    public function append(Interaction $interaction): void;

    /**
     * @return Paginated<Interaction>
     */
    public function forUser(string $userId, int $page, int $perPage): Paginated;
}
