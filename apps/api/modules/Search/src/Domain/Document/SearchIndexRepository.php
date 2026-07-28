<?php

declare(strict_types=1);

namespace EruoFood\Search\Domain\Document;

use EruoFood\Search\Domain\Enum\SearchType;
use EruoFood\Search\Domain\ValueObject\Embedding;
use EruoFood\Search\Domain\ValueObject\SearchQuery;

/**
 * The one place the platform reads its search index. No business module queries
 * its own tables for search — they publish events, Search indexes documents
 * here, and every query (global, typed, autocomplete, similarity) runs against
 * this port. The infrastructure adapter chooses native full-text + pgvector on
 * Postgres, or a portable lexical-prefilter + PHP re-rank elsewhere.
 */
interface SearchIndexRepository
{
    public function save(SearchDocument $document): void;

    public function delete(string $id): void;

    public function deleteBySource(SearchType $type, string $sourceId): void;

    /** Remove any document originating from a source id, whatever its type. */
    public function deleteBySourceId(string $sourceId): void;

    public function find(string $id): ?SearchDocument;

    /**
     * Execute a query. The optional query embedding enables the semantic
     * (vector) component of relevance ranking.
     */
    public function search(SearchQuery $query, ?Embedding $queryEmbedding = null): SearchResults;

    /**
     * Autocomplete: titles/keywords starting with (or containing) the prefix.
     *
     * @return list<string>
     */
    public function suggest(string $prefix, ?SearchType $type, int $limit): array;

    /**
     * Nearest neighbours to a document (vector similarity), excluding itself —
     * the primitive behind "similar" and "related" recommendations.
     *
     * @return list<SearchHit>
     */
    public function similarTo(SearchDocument $document, int $limit): array;

    /**
     * The most popular documents of a type — the fallback for trending/seasonal
     * recommendations and cold-start personalisation.
     *
     * @return list<SearchDocument>
     */
    public function popular(SearchType $type, int $limit): array;

    public function countByType(SearchType $type): int;
}
