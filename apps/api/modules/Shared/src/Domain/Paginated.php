<?php

declare(strict_types=1);

namespace EruoFood\Shared\Domain;

/**
 * Generic pagination container for read/listing operations.
 *
 * @template T
 */
final readonly class Paginated
{
    /**
     * @param list<T> $items
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $page,
        public int $perPage,
    ) {
    }
}
