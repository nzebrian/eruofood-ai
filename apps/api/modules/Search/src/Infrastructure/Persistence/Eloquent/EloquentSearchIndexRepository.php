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
use EruoFood\Search\Domain\ValueObject\Embedding;
use EruoFood\Search\Domain\ValueObject\GeoPoint;
use EruoFood\Search\Domain\ValueObject\SearchQuery;
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

    public function __construct(
        private readonly Ranker $ranker,
        private readonly float $lexicalWeight,
        private readonly int $candidatePool,
        private readonly bool $usePgvector,
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

    public function search(SearchQuery $query, ?Embedding $queryEmbedding = null): SearchResults
    {
        $types = array_map(static fn (SearchType $t): string => $t->value, $query->type->documentTypes());
        $builder = SearchDocumentModel::query()->whereIn('type', $types);
        $this->applyScalarFilters($builder, $query);
        $this->applyLexicalPrefilter($builder, $query->lexicalTerms());
        $this->applyCandidateOrder($builder, $queryEmbedding);

        /** @var list<SearchDocumentModel> $rows */
        $rows = $builder->limit($this->candidatePool)->get()->all();

        $terms = $query->lexicalTerms();
        $matched = [];
        foreach ($rows as $row) {
            $document = $this->toDomain($row);
            if (! $document->facets()->matches($query->filters)) {
                continue;
            }
            $lexical = $this->lexicalScore($row->search_text ?? '', mb_strtolower($row->title), $terms);
            $semantic = $this->semanticScore($queryEmbedding, $document->embedding());
            $distance = $this->distance($query->geo, $document->geo());
            $score = $this->ranker->blend($lexical, $semantic, $document->facets()->popularity);
            $matched[] = new SearchHit(
                document: $document,
                score: $score,
                lexicalScore: $lexical,
                semanticScore: $semantic,
                distanceKm: $distance,
                highlight: $this->highlight($document->description(), $terms),
            );
        }

        $facets = $this->facetCounts($matched);
        $sorted = $this->ranker->sort($matched, $query->sort);
        $total = count($sorted);
        $page = array_slice($sorted, $query->offset(), $query->perPage);

        return new SearchResults($page, $total, $query->page, $query->perPage, $facets);
    }

    public function suggest(string $prefix, ?SearchType $type, int $limit): array
    {
        $needle = mb_strtolower(trim($prefix));
        $builder = SearchDocumentModel::query();
        if ($type !== null && $type !== SearchType::Global) {
            $builder->whereIn('type', array_map(static fn (SearchType $t): string => $t->value, $type->documentTypes()));
        }
        $builder->whereRaw('LOWER(title) LIKE ?', [$needle.'%']);

        /** @var list<string> $titles */
        $titles = $builder->orderByDesc('popularity')->limit($limit)->pluck('title')->all();

        return array_values(array_unique(array_map('strval', $titles)));
    }

    public function similarTo(SearchDocument $document, int $limit): array
    {
        $embedding = $document->embedding();
        $builder = SearchDocumentModel::query()
            ->where('type', $document->type()->value)
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
        $builder = SearchDocumentModel::query();
        if ($type !== SearchType::Global) {
            $builder->whereIn('type', array_map(static fn (SearchType $t): string => $t->value, $type->documentTypes()));
        }

        /** @var list<SearchDocumentModel> $rows */
        $rows = $builder->orderByDesc('popularity')->orderByDesc('rating')->limit($limit)->get()->all();

        return array_map(fn (SearchDocumentModel $m): SearchDocument => $this->toDomain($m), $rows);
    }

    public function countByType(SearchType $type): int
    {
        $builder = SearchDocumentModel::query();
        if ($type !== SearchType::Global) {
            $builder->whereIn('type', array_map(static fn (SearchType $t): string => $t->value, $type->documentTypes()));
        }

        return (int) $builder->count();
    }

    // ---- internals -------------------------------------------------------

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
    }

    /**
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

    private function pgvectorEnabled(): bool
    {
        if ($this->pgvector !== null) {
            return $this->pgvector;
        }
        $connection = SearchDocumentModel::query()->getConnection();
        $this->pgvector = $this->usePgvector
            && $connection->getDriverName() === 'pgsql'
            && $connection->getSchemaBuilder()->hasColumn('search_documents', 'embedding_vec');

        return $this->pgvector;
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
