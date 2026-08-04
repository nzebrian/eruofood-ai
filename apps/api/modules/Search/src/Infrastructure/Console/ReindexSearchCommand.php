<?php

declare(strict_types=1);

namespace EruoFood\Search\Infrastructure\Console;

use EruoFood\Search\Application\Service\SearchIndexManager;
use Illuminate\Console\Command;

/**
 * Rebuilds the search index from the source contexts. Run after a bulk import
 * or a mapping change; day-to-day the index stays current via domain events.
 */
final class ReindexSearchCommand extends Command
{
    protected $signature = 'search:reindex {type? : Limit to one document type (food|recipe|product|vendor)}';

    protected $description = 'Rebuild the search index from the source contexts (read-only backfill).';

    public function handle(SearchIndexManager $indexManager): int
    {
        /** @var string|null $type */
        $type = $this->argument('type');
        $count = $indexManager->reindexAll($type);
        $this->info(sprintf('Reindexed %d document(s)%s.', $count, $type !== null ? " for type \"{$type}\"" : ''));

        return self::SUCCESS;
    }
}
