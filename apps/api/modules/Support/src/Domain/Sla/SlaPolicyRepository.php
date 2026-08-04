<?php

declare(strict_types=1);

namespace EruoFood\Support\Domain\Sla;

use EruoFood\Support\Domain\Enum\TicketPriority;

/** Persistence port for {@see SlaPolicy}. */
interface SlaPolicyRepository
{
    public function findById(string $id): ?SlaPolicy;

    public function findByPriority(TicketPriority $priority): ?SlaPolicy;

    /** @return list<SlaPolicy> */
    public function all(): array;

    public function save(SlaPolicy $policy): void;
}
