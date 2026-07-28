<?php

declare(strict_types=1);

namespace EruoFood\Search\Application\Service;

use EruoFood\Search\Application\Port\EmbeddingGenerator;
use EruoFood\Search\Application\Port\SourceDocumentProvider;
use EruoFood\Search\Domain\Document\SearchIndexRepository;
use EruoFood\Search\Domain\Enum\SearchType;

/**
 * Owns the write side of the index. On a reindex request (from a domain event
 * or the reindex command) it asks the matching {@see SourceDocumentProvider} to
 * hydrate the document from the owning context, generates its embedding, and
 * upserts it — or removes the entry if the source is gone. This is the only
 * component that writes documents; queries never do.
 */
final readonly class SearchIndexManager
{
    /**
     * @param array<string, SourceDocumentProvider> $providers keyed by document type
     */
    public function __construct(
        private SearchIndexRepository $index,
        private EmbeddingGenerator $embedder,
        private array $providers,
    ) {
    }

    /** Re-index a single source item by its type + id. */
    public function reindex(string $type, string $sourceId): void
    {
        $provider = $this->providers[$type] ?? null;
        $searchType = SearchType::tryFrom($type);
        if ($provider === null || $searchType === null) {
            return; // unknown type — nothing to index
        }

        $document = $provider->fetch($sourceId);
        if ($document === null) {
            // Source gone/hidden — remove any indexed doc for it (a vendor
            // source may have been indexed as either restaurant or vendor).
            $this->index->deleteBySourceId($sourceId);

            return;
        }

        $document->assignEmbedding($this->embedder->embed($document->searchableText()));
        $this->index->save($document);
    }

    public function remove(string $type, string $sourceId): void
    {
        $searchType = SearchType::tryFrom($type);
        if ($searchType !== null) {
            $this->index->deleteBySource($searchType, $sourceId);
        }
    }

    /**
     * Full backfill. With no type, reindexes every provider; returns the number
     * of documents (re)indexed.
     */
    public function reindexAll(?string $type = null): int
    {
        $count = 0;
        foreach ($this->providers as $providerType => $provider) {
            if ($type !== null && $type !== $providerType) {
                continue;
            }
            foreach ($provider->allIds() as $sourceId) {
                $this->reindex($providerType, $sourceId);
                $count++;
            }
        }

        return $count;
    }
}
