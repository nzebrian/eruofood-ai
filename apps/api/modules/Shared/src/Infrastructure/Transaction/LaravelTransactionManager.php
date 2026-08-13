<?php

declare(strict_types=1);

namespace EruoFood\Shared\Infrastructure\Transaction;

use EruoFood\Shared\Domain\TransactionManager;
use Illuminate\Database\DatabaseManager;

/**
 * Database-backed {@see TransactionManager}.
 *
 * Laravel's `transaction()` already nests correctly — an inner call becomes a
 * savepoint rather than a second transaction — so services may compose freely.
 * The retry count matters for PostgreSQL under the row locking M23 introduces:
 * two operations that grab the same rows in an unlucky order raise a deadlock,
 * which is a transient condition rather than a business failure. Retrying the
 * whole closure is the correct response; the transaction rolled back, so the
 * work is safe to repeat.
 *
 * Retries only cover deadlock/serialisation errors — Laravel rethrows anything
 * else immediately, so a domain exception surfaces on the first attempt.
 */
final readonly class LaravelTransactionManager implements TransactionManager
{
    public function __construct(
        private DatabaseManager $db,
        private int $attempts = 3,
    ) {
    }

    public function atomic(callable $work): mixed
    {
        $callback = static function () use ($work): mixed {
            return $work();
        };

        // Clamped to at least one attempt: a misconfigured `0` would otherwise
        // mean "never run the work", silently skipping the use case.
        $attempts = max(1, $this->attempts);

        return $this->db->connection()->transaction($callback, $attempts);
    }
}
