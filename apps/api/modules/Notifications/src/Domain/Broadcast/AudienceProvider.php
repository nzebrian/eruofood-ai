<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Domain\Broadcast;

/**
 * Resolves a broadcast segment to concrete recipient user ids. A port so the
 * audience can come from the Identity context, a saved segment, or an explicit
 * list — without the Notifications domain reaching into another context.
 */
interface AudienceProvider
{
    /** @return list<string> user ids for the given segment key */
    public function resolve(string $segment): array;
}
