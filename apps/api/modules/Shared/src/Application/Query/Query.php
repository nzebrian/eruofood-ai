<?php

declare(strict_types=1);

namespace EruoFood\Shared\Application\Query;

/**
 * Marker interface for Queries (read intents).
 *
 * Queries are immutable DTOs describing a request for data. They are handled by
 * a single query handler that returns read models / DTOs, never domain
 * aggregates, keeping the read and write paths separate.
 */
interface Query
{
}
