<?php

declare(strict_types=1);

namespace EruoFood\Search\Domain\Observability;

/**
 * Stable identifiers for everything that can go wrong while indexing
 * (M38-OBS-001).
 *
 * `SearchIndexManager::reindex()` used to `return;` on an unknown type and on a
 * missing source document, with no log, no counter and no trace. An indexer
 * that had stopped working looked exactly like one with nothing to do, and the
 * `admin/failed` endpoint does not help — it reports zero-RESULT search terms,
 * which is a different thing entirely.
 *
 * These codes are a published contract: alert rules and log queries match on
 * them, so renaming one is an operational change, not a refactor.
 */
enum IndexFailure: string
{
    /** An event named a document type with no registered source provider. */
    case UnknownType = 'SEARCH_INDEX_UNKNOWN_TYPE';

    /** The provider returned nothing — source deleted, unpublished or hidden. */
    case SourceMissing = 'SEARCH_INDEX_SOURCE_MISSING';

    /** The provider threw while hydrating from the owning context. */
    case ProviderFailed = 'SEARCH_INDEX_PROVIDER_FAILED';

    /** Embedding generation threw. */
    case EmbeddingFailed = 'SEARCH_INDEX_EMBEDDING_FAILED';

    /** Writing the document (or its vector) to the index threw. */
    case PersistFailed = 'SEARCH_INDEX_PERSIST_FAILED';

    /** A queued reindex exhausted its retries. */
    case JobExhausted = 'SEARCH_INDEX_JOB_EXHAUSTED';

    /**
     * Whether this is an error or an expected outcome.
     *
     * `SourceMissing` is normal — unpublishing removes a document — so it is
     * logged at info. Everything else means indexing is not working.
     */
    public function isError(): bool
    {
        return $this !== self::SourceMissing;
    }

    public function logLevel(): string
    {
        return $this->isError() ? 'error' : 'info';
    }
}
