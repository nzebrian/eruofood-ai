<?php

declare(strict_types=1);

namespace EruoFood\Verification\Infrastructure\Console;

use DateTimeImmutable;
use EruoFood\Verification\Domain\Document\DocumentMetadataRepository;
use Illuminate\Console\Command;

/**
 * Enforces the retention limit on document metadata.
 *
 * Holding regulated detail indefinitely is a liability, not diligence. This
 * removes the metadata for cases closed beyond the retention window while
 * leaving the case and its audit history intact — so the platform can still
 * prove a decision was made and by whom, long after the detail behind it has
 * been discarded.
 */
final class PurgeVerificationDataCommand extends Command
{
    protected $signature = 'verification:purge {--days= : Override the configured retention window} {--dry-run : Report what would be removed}';

    protected $description = 'Remove verification document metadata past its retention window';

    public function handle(DocumentMetadataRepository $documents): int
    {
        $days = (int) ($this->option('days') ?? config('verification.lifecycle.metadata_retention_days', 1825));

        if ($days <= 0) {
            $this->error('Retention window must be a positive number of days.');

            return self::FAILURE;
        }

        $cutoff = (new DateTimeImmutable())->modify(sprintf('-%d days', $days));

        if ($this->option('dry-run')) {
            $this->line(sprintf('Would purge document metadata for cases closed before %s.', $cutoff->format(DATE_ATOM)));

            return self::SUCCESS;
        }

        $removed = $documents->purgeClosedBefore($cutoff);
        $this->info(sprintf('Purged %d document metadata record(s) closed before %s.', $removed, $cutoff->format('Y-m-d')));

        return self::SUCCESS;
    }
}
