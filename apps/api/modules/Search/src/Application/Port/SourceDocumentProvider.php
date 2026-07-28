<?php

declare(strict_types=1);

namespace EruoFood\Search\Application\Port;

use EruoFood\Search\Domain\Document\SearchDocument;

/**
 * Hydrates a {@see SearchDocument} from a source context's own store. One
 * adapter per indexed type (food, recipe, product, vendor) performs a
 * **read-only** lookup over that context's table — a soft reference, never a
 * join or a write. This is how "search reads sources to build its index"
 * without any business module searching, and without Search owning the truth.
 */
interface SourceDocumentProvider
{
    /** The document type this provider builds (e.g. "food"). */
    public function type(): string;

    /**
     * Build the document for a source id, or null if it no longer exists / is
     * not publicly visible (in which case the index entry is removed).
     */
    public function fetch(string $sourceId): ?SearchDocument;

    /**
     * All currently-indexable source ids, for a full reindex/backfill.
     *
     * @return list<string>
     */
    public function allIds(): array;
}
