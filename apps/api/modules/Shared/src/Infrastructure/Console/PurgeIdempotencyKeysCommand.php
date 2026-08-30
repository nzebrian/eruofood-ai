<?php

declare(strict_types=1);

namespace EruoFood\Shared\Infrastructure\Console;

use EruoFood\Shared\Domain\Idempotency\IdempotencyStore;
use Illuminate\Console\Command;
use Throwable;

/**
 * Enforces the retention window on `shared_idempotency_keys` (M42).
 *
 * ## Why this is the first thing M42 does
 *
 * The registry declares `shared.idempotency_keys` at **retainDays: 1** —
 * the tightest window on the platform — with `DeletionMode::Destroy`. Nothing
 * has ever deleted a row. `IdempotencyStore::purgeExpired()` existed and had no
 * production caller at all, so the shortest declared retention had the least
 * enforcement.
 *
 * M41 raised the stakes. Before it, `user_id` on this table was always null;
 * M41 began writing the authenticated principal into it. So the table now
 * accumulates, without limit, rows that pair a principal with
 * `response_snapshot` — the response body of a money-moving operation across
 * eight scopes: checkout, dispatch acceptance, payment initiation, refunds,
 * both wallet operations and subscriptions.
 *
 * ## Why there is no `--days`
 *
 * Deliberately absent, unlike `search:purge-query-log`. Eligibility here is
 * `expires_at < now` and nothing else.
 *
 * A claim's *age* is not its eligibility. `expires_at` is what the store itself
 * consults to decide whether a key may still be replayed or reclaimed, so a row
 * is safe to delete exactly when the store has stopped honouring it. Offering a
 * `--days` window over `created_at` would let an operator delete a live claim,
 * and a deleted live claim reopens the duplicate-payment window that claim
 * exists to close: the retry it would have collapsed executes a second time.
 *
 * The retention period is therefore set where the claim is written — the TTL in
 * `config/shared.php` — and honoured here, rather than being a flag on a
 * destructive command.
 *
 * ## What it does not print
 *
 * No keys, no request hashes, no response snapshots, no user ids. This command
 * exists because those values should not persist; echoing them into an
 * operator's terminal on the way to deleting them would defeat the purpose.
 * Counts and timestamps only.
 */
final class PurgeIdempotencyKeysCommand extends Command
{
    protected $signature = 'shared:purge-idempotency-keys
        {--chunk= : Rows to delete per statement}
        {--dry-run : Report what would be removed, and remove nothing}';

    protected $description = 'Remove idempotency claims that have passed their expiry';

    public function handle(IdempotencyStore $store): int
    {
        $chunk = (int) ($this->option('chunk') ?? config('shared.idempotency.purge_chunk', 1000));

        if ($chunk <= 0) {
            $this->error('Chunk size must be a positive number of rows.');

            return self::FAILURE;
        }

        try {
            $eligible = $store->countExpired();

            if ($this->option('dry-run')) {
                $this->line(sprintf(
                    'Dry run: %d idempotency claim(s) expired before %s are eligible. Nothing was deleted.',
                    $eligible,
                    now()->toDateTimeImmutable()->format(DATE_ATOM),
                ));

                return self::SUCCESS;
            }

            $removed = $store->purgeExpired($chunk);
        } catch (Throwable $e) {
            // Not swallowed. A purge that failed quietly is indistinguishable
            // from one that found nothing, and the retention claim would become
            // false without anybody noticing.
            $this->error(sprintf('Idempotency purge failed: %s', $e->getMessage()));

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Purged %d of %d expired idempotency claim(s) as at %s. Unexpired claims were left alone.',
            $removed,
            $eligible,
            now()->toDateTimeImmutable()->format('Y-m-d H:i:s'),
        ));

        return self::SUCCESS;
    }
}
