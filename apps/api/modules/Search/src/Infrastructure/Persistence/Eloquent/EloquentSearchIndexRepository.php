<?php

declare(strict_types=1);

namespace EruoFood\Search\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use EruoFood\Search\Domain\Document\DocumentFacets;
use EruoFood\Search\Domain\Document\Ranker;
use EruoFood\Search\Domain\Document\SearchDocument;
use EruoFood\Search\Domain\Document\SearchHit;
use EruoFood\Search\Domain\Document\SearchIndexRepository;
use EruoFood\Search\Domain\Document\SearchResults;
use EruoFood\Search\Domain\Enum\SearchType;
use EruoFood\Search\Domain\Enum\SortOption;
use EruoFood\Search\Domain\Exception\SearchPaginationTooDeep;
use EruoFood\Search\Domain\ValueObject\Embedding;
use EruoFood\Search\Domain\ValueObject\GeoPoint;
use EruoFood\Search\Domain\ValueObject\SearchQuery;
use EruoFood\Search\Infrastructure\Capability\SearchCapabilityProbe;
use EruoFood\Search\Infrastructure\Persistence\Eloquent\Model\SearchDocumentModel;
use Illuminate\Database\Eloquent\Builder;

/**
 * The index adapter. Recall is pushed to SQL (type + scalar filters + a lexical
 * LIKE prefilter, or pgvector KNN when available); the bounded candidate pool is
 * then re-ranked in PHP with identical semantics everywhere — full filter
 * matching, lexical + semantic (cosine) scoring blended by the {@see Ranker},
 * geo distance, sort and pagination. On Postgres the stored embedding is
 * mirrored into a `vector` column (ivfflat) that only accelerates candidate
 * selection; the ranking maths never changes, so tests on sqlite are faithful.
 */
final class EloquentSearchIndexRepository implements SearchIndexRepository
{
    private ?bool $pgvector = null;

    private float $pgvectorProbedAt = 0.0;

    /**
     * @param float $capabilityTtlSeconds how long a probed capability answer may
     *                                    be reused; see {@see self::pgvectorEnabled()}
     * @param SearchCapabilityProbe|null $probe injected only by tests; production
     *                                          builds one over the live connection
     */
    public function __construct(
        private readonly Ranker $ranker,
        private readonly int $candidatePool,
        private readonly bool $usePgvector,
        private readonly int $maxResultWindow = 1000,
        private readonly float $capabilityTtlSeconds = 30.0,
        private readonly ?SearchCapabilityProbe $probe = null,
    ) {
    }

    public function save(SearchDocument $document): void
    {
        $facets = $document->facets();
        $model = SearchDocumentModel::query()->find($document->id()) ?? new SearchDocumentModel();
        $model->id = $document->id();
        $model->type = $document->type()->value;
        $model->source_id = $document->sourceId();
        $model->title = $document->title();
        $model->description = $document->description();
        $model->search_text = mb_strtolower($document->searchableText());
        $model->keywords = $document->keywords();
        $model->url = $document->url();
        $model->image = $document->image();
        $model->locale = $document->locale();
        $model->facets = $facets->toArray();
        $model->region = $facets->region;
        $model->cuisine = $facets->cuisine;
        $model->category = $facets->category;
        $model->difficulty = $facets->difficulty;
        $model->restaurant_id = $facets->restaurantId;
        $model->vendor_id = $facets->vendorId;
        $model->popularity = $facets->popularity;
        $model->rating = $facets->rating;
        $model->price_minor = $facets->priceMinor;
        $model->prep_time_minutes = $facets->prepTimeMinutes;
        $model->calories = $facets->calories;
        $model->latitude = $document->geo()?->latitude;
        $model->longitude = $document->geo()?->longitude;
        $model->embedding = $document->embedding()?->toArray() ?? [];
        $model->created_at = $document->createdAt();
        $model->updated_at = $document->updatedAt();
        $model->save();

        $this->writeVector($model->id, $document->embedding());
    }

    public function delete(string $id): void
    {
        SearchDocumentModel::query()->whereKey($id)->delete();
    }

    public function deleteBySource(SearchType $type, string $sourceId): void
    {
        SearchDocumentModel::query()
            ->where('type', $type->value)
            ->where('source_id', $sourceId)
            ->delete();
    }

    public function deleteBySourceId(string $sourceId): void
    {
        SearchDocumentModel::query()->where('source_id', $sourceId)->delete();
    }

    public function find(string $id): ?SearchDocument
    {
        $m = SearchDocumentModel::query()->find($id);

        return $m !== null ? $this->toDomain($m) : null;
    }

    /**
     * Execute a query (M38-SEARCH-001).
     *
     * ## What this replaces
     *
     * The previous implementation fetched a fixed 200-row candidate pool,
     * scored it in PHP, then reported `count($sorted)` as the total and
     * `array_slice($sorted, $offset, $perPage)` as the page. Two consequences:
     * any query matching more than 200 documents reported a false total, and
     * every page past offset 200 came back EMPTY while the response still
     * claimed more results existed.
     *
     * ## What happens now
     *
     * `$total` is a real `COUNT(*)` over the SQL predicates — never the size of
     * a truncated pool. Pagination then takes one of two paths:
     *
     * - **SQL-ordered sorts** (popularity, rating, newest, price, prep time)
     *   are ordered and paginated by PostgreSQL with LIMIT/OFFSET. Exact at any
     *   depth, no in-memory scan.
     * - **Relevance and distance** are blended in PHP and need a materialised
     *   window. It is bounded by `search.max_result_window`, and the window is
     *   sized to cover the requested page rather than a fixed 200. A page past
     *   the bound raises {@see SearchPaginationTooDeep} — an explicit refusal,
     *   never a silent empty page.
     */
    public function search(SearchQuery $query, ?Embedding $queryEmbedding = null): SearchResults
    {
        $types = $this->scopeTypes($query->type);

        $predicate = function () use ($types, $query): Builder {
            $builder = SearchDocumentModel::query()->whereIn('type', $types);
            $this->applyScalarFilters($builder, $query);
            $this->applyLexicalPrefilter($builder, $query->lexicalTerms());

            return $builder;
        };

        // Truthful total: the whole matching set, not the slice we happened to
        // materialise. `$exact` is false only when a filter cannot be fully
        // expressed in SQL (see applyScalarFilters), in which case the count is
        // an upper bound and the response says so rather than pretending.
        $total = $predicate()->count();
        $exact = $this->filtersAreFullySql($query);

        $sqlOrder = $this->sqlOrdering($query->sort);

        if ($sqlOrder !== null && $exact) {
            $builder = $predicate();
            foreach ($sqlOrder as [$column, $direction]) {
                $builder->orderByRaw($column.' '.$direction);
            }
            // Stable tiebreak, so equal keys do not reshuffle between pages.
            $builder->orderBy('id');

            /** @var list<SearchDocumentModel> $rows */
            $rows = $builder->skip($query->offset())->take($query->perPage)->get()->all();
            $hits = $this->hydrateHits($rows, $query, $queryEmbedding);

            // Facets describe the whole result set, so they are counted from a
            // bounded sample rather than from this one page.
            return new SearchResults($hits, $total, $query->page, $query->perPage, $this->sampledFacets($predicate(), $query), $exact);
        }

        // PHP-ranked path. Size the window to cover the requested page.
        //
        // The boundary rule is that the WHOLE page must fit inside the window:
        // `offset + perPage <= max_result_window`. The earlier rule tested only
        // `offset >= window`, which let a straddling page through — offset 995
        // with perPage 20 against a 1000-row window was accepted, clamped, and
        // answered with 5 hits while `total` reported the full match count. A
        // short page with no signal is the same silent lie this defect is
        // about, just one page further in.
        $needed = $query->offset() + $query->perPage;

        if ($needed > $this->maxResultWindow) {
            throw SearchPaginationTooDeep::beyond($this->maxResultWindow, $query->offset(), $query->perPage);
        }

        $window = min(max($this->candidatePool, $needed), $this->maxResultWindow);

        $builder = $predicate();
        $this->applyCandidateOrder($builder, $queryEmbedding);

        /** @var list<SearchDocumentModel> $rows */
        $rows = $builder->limit($window)->get()->all();

        $matched = $this->hydrateHits($rows, $query, $queryEmbedding, filter: true);

        $facets = $this->facetCounts($matched);
        $sorted = $this->ranker->sort($matched, $query->sort);
        $page = array_slice($sorted, $query->offset(), $query->perPage);

        return new SearchResults($page, $total, $query->page, $query->perPage, $facets, $exact);
    }

    /**
     * Turn rows into ranked hits. When `$filter` is set the PHP-only filter
     * refinement is applied too (the SQL path has already filtered exactly).
     *
     * @param list<SearchDocumentModel> $rows
     * @return list<SearchHit>
     */
    private function hydrateHits(array $rows, SearchQuery $query, ?Embedding $queryEmbedding, bool $filter = false): array
    {
        $terms = $query->lexicalTerms();
        $hits = [];

        foreach ($rows as $row) {
            $document = $this->toDomain($row);

            if ($filter && ! $document->facets()->matches($query->filters)) {
                continue;
            }

            $lexical = $this->lexicalScore($row->search_text ?? '', mb_strtolower($row->title), $terms);
            $semantic = $this->semanticScore($queryEmbedding, $document->embedding());
            $hits[] = new SearchHit(
                document: $document,
                score: $this->ranker->blend($lexical, $semantic, $document->facets()->popularity),
                lexicalScore: $lexical,
                semanticScore: $semantic,
                distanceKm: $this->distance($query->geo, $document->geo()),
                highlight: $this->highlight($document->description(), $terms),
            );
        }

        return $hits;
    }

    /**
     * Facet counts for the SQL-paginated path, over a bounded sample of the
     * matching set. Counting one page would be misleading; counting the whole
     * corpus would be unbounded.
     *
     * @param Builder<SearchDocumentModel> $builder
     * @return array<string, array<string, int>>
     */
    private function sampledFacets(Builder $builder, SearchQuery $query): array
    {
        /** @var list<SearchDocumentModel> $rows */
        $rows = $builder->limit($this->candidatePool)->get()->all();

        return $this->facetCounts($this->hydrateHits($rows, $query, null, filter: true));
    }

    /**
     * The ORDER BY for sorts PostgreSQL can express, or null when ranking has
     * to happen in PHP.
     *
     * `NULLS LAST` is spelled as `(col IS NULL), col` so the same ordering holds
     * on SQLite, where the test suite runs — a sort that differs by driver
     * would make those tests describe a system nobody deploys.
     *
     * @return list<array{string, string}>|null
     */
    private function sqlOrdering(SortOption $sort): ?array
    {
        return match ($sort) {
            SortOption::Popularity => [['popularity', 'DESC']],
            SortOption::Rating => [['rating', 'DESC']],
            SortOption::Newest => [['updated_at', 'DESC']],
            SortOption::Price => [['(price_minor IS NULL)', 'ASC'], ['price_minor', 'ASC']],
            SortOption::PreparationTime => [['(prep_time_minutes IS NULL)', 'ASC'], ['prep_time_minutes', 'ASC']],
            // Relevance blends lexical + semantic + popularity in PHP;
            // Distance is a haversine over lat/lng. Neither is a column.
            SortOption::Relevance, SortOption::Distance => null,
        };
    }

    /**
     * Whether every active filter was fully expressed in SQL.
     *
     * `state` is matched against a JSON array inside `facets`, so
     * applyScalarFilters can only prefilter it coarsely; the exact test happens
     * in PHP. When it is in play the COUNT is an upper bound, and
     * `SearchResults::$totalIsExact` reports that rather than overstating.
     */
    private function filtersAreFullySql(SearchQuery $query): bool
    {
        return $query->filters->state === null;
    }

    public function suggest(string $prefix, ?SearchType $type, int $limit): array
    {
        $needle = mb_strtolower(trim($prefix));

        // M38-SEC-001. This used to skip the type filter entirely for `Global`
        // (and for null), so `/autocomplete?q=ada` — the DEFAULT public request
        // shape — read every row in the index, `user` documents included. The
        // scope is now always applied.
        $builder = SearchDocumentModel::query()->whereIn('type', $this->scopeTypes($type));
        $builder->whereRaw('LOWER(title) LIKE ?', [$needle.'%']);

        /** @var list<string> $titles */
        $titles = $builder->orderByDesc('popularity')->limit($limit)->pluck('title')->all();

        return array_values(array_unique(array_map('strval', $titles)));
    }

    public function similarTo(SearchDocument $document, int $limit): array
    {
        $embedding = $document->embedding();

        // Routed through the same scope authority as every other read. For a
        // concrete type this is the anchor's own type, which is what it always
        // was; going through scopeTypes() means there is exactly one place in
        // this class that decides which document types a query may touch.
        $builder = SearchDocumentModel::query()
            ->whereIn('type', $this->scopeTypes($document->type()))
            ->where('id', '!=', $document->id());

        if ($this->pgvectorEnabled() && $embedding !== null && ! $embedding->isEmpty()) {
            $builder->orderByRaw('embedding_vec <=> '.$this->vectorLiteral($embedding));
            /** @var list<SearchDocumentModel> $rows */
            $rows = $builder->limit($limit)->get()->all();

            return array_map(fn (SearchDocumentModel $m): SearchHit => $this->neighbourHit($this->toDomain($m), $embedding), $rows);
        }

        // Portable path: pull a candidate pool by popularity, cosine re-rank in PHP.
        /** @var list<SearchDocumentModel> $rows */
        $rows = $builder->orderByDesc('popularity')->limit($this->candidatePool)->get()->all();
        $hits = array_map(fn (SearchDocumentModel $m): SearchHit => $this->neighbourHit($this->toDomain($m), $embedding), $rows);
        usort($hits, static fn (SearchHit $a, SearchHit $b): int => $b->semanticScore <=> $a->semanticScore);

        return array_slice($hits, 0, $limit);
    }

    public function popular(SearchType $type, int $limit): array
    {
        // M38-SEC-001. Same defect as suggest(), and worse in consequence:
        // `/recommendations?kind=trending` presents the WHOLE document, not
        // just a title, and `Global` skipped the filter.
        $builder = SearchDocumentModel::query()->whereIn('type', $this->scopeTypes($type));

        /** @var list<SearchDocumentModel> $rows */
        $rows = $builder->orderByDesc('popularity')->orderByDesc('rating')->limit($limit)->get()->all();

        return array_map(fn (SearchDocumentModel $m): SearchDocument => $this->toDomain($m), $rows);
    }

    public function countByType(SearchType $type): int
    {
        return (int) SearchDocumentModel::query()
            ->whereIn('type', $this->scopeTypes($type))
            ->count();
    }

    // ---- internals -------------------------------------------------------

    /**
     * The document types a scope may read (M38-SEC-001).
     *
     * The one place the query layer decides what a scope means, and it always
     * decides something: there is no branch that leaves the type filter off.
     * A null scope is the public `Global` fan-out, which
     * {@see SearchType::documentTypes()} derives so that admin-only types are
     * excluded by construction rather than by a list somebody must remember to
     * update.
     *
     * @return list<string>
     */
    private function scopeTypes(?SearchType $type): array
    {
        return ($type ?? SearchType::Global)->documentTypeValues();
    }

    /** @param Builder<SearchDocumentModel> $builder */
    private function applyScalarFilters(Builder $builder, SearchQuery $query): void
    {
        $f = $query->filters;
        if ($f->region !== null) {
            $builder->whereRaw('LOWER(region) = ?', [mb_strtolower($f->region)]);
        }
        if ($f->cuisine !== null) {
            $builder->whereRaw('LOWER(cuisine) = ?', [mb_strtolower($f->cuisine)]);
        }
        if ($f->category !== null) {
            $builder->whereRaw('LOWER(category) = ?', [mb_strtolower($f->category)]);
        }
        if ($f->difficulty !== null) {
            $builder->whereRaw('LOWER(difficulty) = ?', [mb_strtolower($f->difficulty)]);
        }
        if ($f->restaurantId !== null) {
            $builder->where('restaurant_id', $f->restaurantId);
        }
        if ($f->vendorId !== null) {
            $builder->where('vendor_id', $f->vendorId);
        }
        if ($f->minRating !== null) {
            $builder->where('rating', '>=', $f->minRating);
        }
        if ($f->maxCalories !== null) {
            $builder->where(fn (Builder $q) => $q->whereNull('calories')->orWhere('calories', '<=', $f->maxCalories));
        }
        if ($f->maxCookingTime !== null) {
            $builder->where(fn (Builder $q) => $q->whereNull('prep_time_minutes')->orWhere('prep_time_minutes', '<=', $f->maxCookingTime));
        }
        if ($f->minPriceMinor !== null) {
            $builder->where('price_minor', '>=', $f->minPriceMinor);
        }
        if ($f->maxPriceMinor !== null) {
            $builder->where('price_minor', '<=', $f->maxPriceMinor);
        }
        if ($f->state !== null) {
            // `states` is a JSON array inside `facets`, so this is a coarse
            // text prefilter — enough to bound the COUNT and the window, not
            // enough to be exact. DocumentFacets::matches() still applies the
            // precise test in PHP, and filtersAreFullySql() reports the
            // resulting total as inexact rather than overstating it.
            $builder->whereRaw('LOWER(CAST(facets AS TEXT)) LIKE ?', ['%"'.mb_strtolower($f->state).'"%']);
        }
    }

    /**
     * @param list<string> $terms
     */
    /**
     * @param Builder<SearchDocumentModel> $builder
     * @param list<string> $terms
     */
    private function applyLexicalPrefilter(Builder $builder, array $terms): void
    {
        if ($terms === []) {
            return;
        }
        $builder->where(function (Builder $q) use ($terms): void {
            foreach ($terms as $term) {
                $q->orWhereRaw('search_text LIKE ?', ['%'.$term.'%']);
            }
        });
    }

    /** @param Builder<SearchDocumentModel> $builder */
    private function applyCandidateOrder(Builder $builder, ?Embedding $embedding): void
    {
        if ($this->pgvectorEnabled() && $embedding !== null && ! $embedding->isEmpty()) {
            $builder->orderByRaw('embedding_vec <=> '.$this->vectorLiteral($embedding));

            return;
        }
        $builder->orderByDesc('popularity');
    }

    /**
     * @param list<string> $terms
     */
    /** @param list<string> $terms */
    private function lexicalScore(string $searchText, string $title, array $terms): float
    {
        if ($terms === []) {
            return 0.0;
        }
        $score = 0.0;
        foreach ($terms as $term) {
            if ($term !== '' && str_contains($searchText, $term)) {
                $score += 1.0;
                if (str_contains($title, $term)) {
                    $score += 0.5;
                }
            }
        }

        return min(1.0, $score / (count($terms) * 1.5));
    }

    private function semanticScore(?Embedding $query, ?Embedding $document): float
    {
        if ($query === null || $document === null || $query->isEmpty() || $document->isEmpty()) {
            return 0.0;
        }

        return (max(-1.0, min(1.0, $query->cosineTo($document))) + 1.0) / 2.0;
    }

    private function neighbourHit(SearchDocument $document, ?Embedding $anchor): SearchHit
    {
        $semantic = $this->semanticScore($anchor, $document->embedding());

        return new SearchHit($document, $semantic, 0.0, $semantic);
    }

    private function distance(?GeoPoint $from, ?GeoPoint $to): ?float
    {
        if ($from === null || $to === null) {
            return null;
        }

        return $from->distanceKmTo($to);
    }

    /**
     * @param list<string> $terms
     */
    /** @param list<string> $terms */
    private function highlight(string $description, array $terms): ?string
    {
        $snippet = mb_substr($description, 0, 160);

        return $snippet === '' ? null : $snippet;
    }

    /**
     * @param list<SearchHit> $hits
     * @return array<string, array<string, int>>
     */
    private function facetCounts(array $hits): array
    {
        $facets = ['type' => [], 'region' => [], 'cuisine' => []];
        foreach ($hits as $hit) {
            $facets['type'][$hit->document->type()->value] = ($facets['type'][$hit->document->type()->value] ?? 0) + 1;
            $region = $hit->document->facets()->region;
            if ($region !== null && $region !== '') {
                $facets['region'][$region] = ($facets['region'][$region] ?? 0) + 1;
            }
            $cuisine = $hit->document->facets()->cuisine;
            if ($cuisine !== null && $cuisine !== '') {
                $facets['cuisine'][$cuisine] = ($facets['cuisine'][$cuisine] ?? 0) + 1;
            }
        }

        return $facets;
    }

    private function writeVector(string $id, ?Embedding $embedding): void
    {
        if (! $this->pgvectorEnabled() || $embedding === null || $embedding->isEmpty()) {
            return;
        }
        SearchDocumentModel::query()->whereKey($id)
            ->update(['embedding_vec' => \Illuminate\Support\Facades\DB::raw($this->vectorLiteral($embedding))]);
    }

    private function vectorLiteral(Embedding $embedding): string
    {
        $values = implode(',', array_map(static fn (float $v): string => (string) round($v, 6), $embedding->toArray()));

        return "'[".$values."]'::vector";
    }

    /**
     * Whether native KNN is genuinely usable right now (M38-VECTOR-001).
     *
     * The old check asked `hasColumn('search_documents', 'embedding_vec')`,
     * which answers a different question: the column can exist while the
     * `vector` extension or the ivfflat index does not, and then `ORDER BY
     * embedding_vec <=> …` is either an error or an unindexed scan. It also
     * meant "native vector search" was reported purely from a column's
     * presence, with no way for anything to notice the difference.
     *
     * This asks the capability probe, which queries `pg_extension` and
     * `pg_indexes`. A probe failure is NOT rounded down to "available".
     *
     * ## Why the answer expires
     *
     * The answer used to be memoised for the lifetime of the instance. This
     * repository is bound as a container singleton and is held by further
     * singletons (`SearchService`, `SearchIndexManager`, …). Under PHP-FPM —
     * which is what this application deploys on; there is no Octane, Swoole or
     * RoadRunner in `composer.json` — the container is rebuilt per request, so
     * that memo really was request-scoped on the web path. A QUEUE WORKER is
     * not: it is a long-lived process, so a worker that started before the
     * acceleration migration provisioned `vector` would have cached "absent"
     * and never written the `embedding_vec` column again for as long as it ran.
     *
     * So the memo is bounded rather than permanent. Two catalog lookups per
     * `search.capability_ttl` seconds is a negligible cost next to re-probing
     * on every indexed document during a backfill, and a capability that
     * appears at runtime is picked up within that bound instead of never.
     */
    private function pgvectorEnabled(): bool
    {
        if (! $this->usePgvector) {
            return false;
        }

        $now = microtime(true);

        if ($this->pgvector !== null && ($now - $this->pgvectorProbedAt) < $this->capabilityTtlSeconds) {
            return $this->pgvector;
        }

        $this->pgvectorProbedAt = $now;

        return $this->pgvector = $this->capabilityProbe()->probe()->nativeVectorSearchActive();
    }

    private function capabilityProbe(): SearchCapabilityProbe
    {
        if ($this->probe !== null) {
            return $this->probe;
        }

        /** @var \Illuminate\Database\Connection $connection */
        $connection = SearchDocumentModel::query()->getConnection();

        return new SearchCapabilityProbe($connection, $connection->getDriverName(), true, false);
    }

    private function toDomain(SearchDocumentModel $m): SearchDocument
    {
        /** @var list<string> $keywords */
        $keywords = $m->keywords ?? [];
        /** @var array<string, mixed> $facetsRaw */
        $facetsRaw = $m->facets ?? [];
        /** @var list<float> $embeddingRaw */
        $embeddingRaw = $m->embedding ?? [];
        $geo = ($m->latitude !== null && $m->longitude !== null)
            ? new GeoPoint((float) $m->latitude, (float) $m->longitude)
            : null;

        return SearchDocument::reconstitute(
            id: $m->id,
            type: SearchType::from($m->type),
            sourceId: $m->source_id,
            title: $m->title,
            description: (string) $m->description,
            keywords: array_map('strval', $keywords),
            url: $m->url,
            image: $m->image,
            locale: (string) ($m->locale ?? 'en'),
            facets: DocumentFacets::fromArray($facetsRaw),
            geo: $geo,
            embedding: $embeddingRaw !== [] ? new Embedding(array_map('floatval', $embeddingRaw)) : null,
            createdAt: DateTimeImmutable::createFromInterface($m->created_at),
            updatedAt: DateTimeImmutable::createFromInterface($m->updated_at),
        );
    }
}
