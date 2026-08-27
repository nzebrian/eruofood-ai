<?php

declare(strict_types=1);

namespace EruoFood\Search\Application\Service;

use EruoFood\Search\Application\Port\EmbeddingGenerator;
use EruoFood\Search\Application\Port\SearchCache;
use EruoFood\Search\Application\Port\SourceDocumentProvider;
use EruoFood\Search\Domain\Document\SearchIndexRepository;
use EruoFood\Search\Domain\Enum\SearchType;
use EruoFood\Search\Domain\Observability\IndexFailure;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Owns the write side of the index. On a reindex request (from a queued job or
 * the reindex command) it asks the matching {@see SourceDocumentProvider} to
 * hydrate the document from the owning context, generates its embedding, and
 * upserts it — or removes the entry if the source is gone. This is the only
 * component that writes documents; queries never do.
 *
 * ## M38 — failures are visible, and invalidation is bounded
 *
 * Two defects were fixed here.
 *
 * **It used to fail in silence (M38-OBS-001).** An unknown document type and a
 * missing source row both hit a bare `return;`. An indexer that had stopped
 * working was indistinguishable from one with nothing to do. Every exit now
 * carries a stable {@see IndexFailure} code, and provider/embedding/persist
 * failures are distinguished from one another instead of all surfacing as a
 * generic exception — or as nothing at all.
 *
 * **It used to flush the whole application cache, per document
 * (M38-CACHE-001).** `reindexAll()` calls `reindex()` in a loop, so a backfill
 * of N documents issued N store-wide flushes. Invalidation is now deferrable:
 * a backfill suppresses per-document invalidation and invalidates once at the
 * end, and `SearchCache::flush()` no longer touches anything outside the Search
 * namespace anyway.
 */
final class SearchIndexManager
{
    /**
     * While true, per-document invalidation is suppressed and recorded, so a
     * batch can invalidate exactly once when it finishes.
     */
    private bool $deferInvalidation = false;

    private bool $invalidationPending = false;

    /**
     * @param array<string, SourceDocumentProvider> $providers keyed by document type
     */
    public function __construct(
        private readonly SearchIndexRepository $index,
        private readonly EmbeddingGenerator $embedder,
        private readonly SearchCache $cache,
        private readonly array $providers,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /** Re-index a single source item by its type + id. */
    public function reindex(string $type, string $sourceId): void
    {
        $provider = $this->providers[$type] ?? null;
        $searchType = SearchType::tryFrom($type);

        if ($provider === null || $searchType === null) {
            // Previously a silent `return`. An event pointing at a type nobody
            // indexes means the event map and the provider list have drifted
            // apart, and nothing would ever have said so.
            $this->report(IndexFailure::UnknownType, $type, $sourceId, [
                'known_types' => array_keys($this->providers),
            ]);

            return;
        }

        try {
            $document = $provider->fetch($sourceId);
        } catch (Throwable $e) {
            $this->report(IndexFailure::ProviderFailed, $type, $sourceId, [
                'exception' => $e->getMessage(),
            ]);

            // Rethrown so a queued attempt retries and, if it keeps failing,
            // lands in failed_jobs rather than disappearing.
            throw $e;
        }

        if ($document === null) {
            // Source gone/hidden — remove any indexed doc for it (a vendor
            // source may have been indexed as either restaurant or vendor).
            // Expected, so it is reported at info, not as an error.
            $this->report(IndexFailure::SourceMissing, $type, $sourceId);
            $this->index->deleteBySourceId($sourceId);
            $this->invalidate();

            return;
        }

        try {
            $document->assignEmbedding($this->embedder->embed($document->searchableText()));
        } catch (Throwable $e) {
            $this->report(IndexFailure::EmbeddingFailed, $type, $sourceId, [
                'exception' => $e->getMessage(),
            ]);

            throw $e;
        }

        try {
            $this->index->save($document);
        } catch (Throwable $e) {
            $this->report(IndexFailure::PersistFailed, $type, $sourceId, [
                'exception' => $e->getMessage(),
            ]);

            throw $e;
        }

        // The index changed — drop cached result sets so queries reflect it
        // immediately (stale hits would otherwise surface unpublished content).
        $this->invalidate();
    }

    public function remove(string $type, string $sourceId): void
    {
        $searchType = SearchType::tryFrom($type);

        if ($searchType === null) {
            $this->report(IndexFailure::UnknownType, $type, $sourceId);

            return;
        }

        $this->index->deleteBySource($searchType, $sourceId);
        $this->invalidate();
    }

    /**
     * Full backfill. With no type, reindexes every provider; returns the number
     * of documents (re)indexed.
     *
     * Invalidation is deferred for the whole run: one invalidation at the end
     * instead of one per document.
     */
    public function reindexAll(?string $type = null): int
    {
        $count = 0;
        $this->deferInvalidation = true;

        try {
            foreach ($this->providers as $providerType => $provider) {
                if ($type !== null && $type !== $providerType) {
                    continue;
                }

                foreach ($provider->allIds() as $sourceId) {
                    $this->reindex($providerType, $sourceId);
                    $count++;
                }
            }
        } finally {
            // Always ends the batch, so an exception mid-backfill cannot leave
            // invalidation suppressed for every later request in the process.
            $this->deferInvalidation = false;

            if ($this->invalidationPending) {
                $this->invalidationPending = false;
                $this->cache->flush();
            }
        }

        return $count;
    }

    private function invalidate(): void
    {
        if ($this->deferInvalidation) {
            $this->invalidationPending = true;

            return;
        }

        $this->cache->flush();
    }

    /**
     * @param array<string, mixed> $context
     */
    private function report(IndexFailure $failure, string $type, string $sourceId, array $context = []): void
    {
        // Identifiers only. Titles, descriptions and keywords are never logged:
        // indexed content is hydrated from other contexts and the log store is
        // not cleared to hold it.
        $this->logger?->log($failure->logLevel(), $failure->value, [
            'code' => $failure->value,
            'document_type' => $type,
            'source_id' => $sourceId,
            ...$context,
        ]);
    }
}
