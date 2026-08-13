<?php

declare(strict_types=1);

namespace EruoFood\Shared\Domain\Exception;

/**
 * Two operations tried to mutate the same aggregate at once and the loser was
 * rejected rather than silently overwriting the winner (a lost update).
 *
 * Raised by optimistic version checks in the persistence layer. It maps to HTTP
 * 409 so a client can safely retry: the operation did not happen.
 */
final class ConcurrencyConflict extends DomainException
{
    public static function on(string $aggregate, string $id): self
    {
        return new self(sprintf(
            'The %s "%s" was modified by another request. Nothing was changed — please retry.',
            $aggregate,
            $id,
        ));
    }

    public function errorCode(): string
    {
        return 'CONCURRENCY_CONFLICT';
    }
}
