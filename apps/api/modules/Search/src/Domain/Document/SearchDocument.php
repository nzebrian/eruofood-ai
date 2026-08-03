<?php

declare(strict_types=1);

namespace EruoFood\Search\Domain\Document;

use DateTimeImmutable;
use EruoFood\Search\Domain\Enum\SearchType;
use EruoFood\Search\Domain\ValueObject\Embedding;
use EruoFood\Search\Domain\ValueObject\GeoPoint;

/**
 * A single indexed item — the aggregate root of the search index. It is a
 * denormalised projection of something owned by another context (a food, a
 * recipe, a product, a vendor), assembled by the index manager from a read-only
 * source lookup. Search owns the document; the source context owns the truth.
 *
 * The document id is deterministic (`type:sourceId`) so re-indexing the same
 * source upserts rather than duplicates.
 */
final class SearchDocument
{
    private function __construct(
        private readonly string $id,
        private readonly SearchType $type,
        private readonly string $sourceId,
        private string $title,
        private string $description,
        private array $keywords,
        private ?string $url,
        private ?string $image,
        private string $locale,
        private DocumentFacets $facets,
        private ?GeoPoint $geo,
        private ?Embedding $embedding,
        private readonly DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt,
    ) {
    }

    public static function idFor(SearchType $type, string $sourceId): string
    {
        return $type->value.':'.$sourceId;
    }

    /**
     * @param list<string> $keywords
     */
    public static function create(
        SearchType $type,
        string $sourceId,
        string $title,
        string $description,
        array $keywords,
        ?string $url,
        ?string $image,
        string $locale,
        DocumentFacets $facets,
        ?GeoPoint $geo,
        DateTimeImmutable $now,
    ): self {
        return new self(
            self::idFor($type, $sourceId),
            $type,
            $sourceId,
            $title,
            $description,
            $keywords,
            $url,
            $image,
            $locale,
            $facets,
            $geo,
            null,
            $now,
            $now,
        );
    }

    /**
     * @param list<string> $keywords
     */
    public static function reconstitute(
        string $id,
        SearchType $type,
        string $sourceId,
        string $title,
        string $description,
        array $keywords,
        ?string $url,
        ?string $image,
        string $locale,
        DocumentFacets $facets,
        ?GeoPoint $geo,
        ?Embedding $embedding,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
    ): self {
        return new self($id, $type, $sourceId, $title, $description, $keywords, $url, $image, $locale, $facets, $geo, $embedding, $createdAt, $updatedAt);
    }

    /**
     * @param list<string> $keywords
     */
    public function refresh(
        string $title,
        string $description,
        array $keywords,
        ?string $url,
        ?string $image,
        DocumentFacets $facets,
        ?GeoPoint $geo,
        DateTimeImmutable $now,
    ): void {
        $this->title = $title;
        $this->description = $description;
        $this->keywords = $keywords;
        $this->url = $url;
        $this->image = $image;
        $this->facets = $facets;
        $this->geo = $geo;
        $this->updatedAt = $now;
    }

    public function assignEmbedding(Embedding $embedding): void
    {
        $this->embedding = $embedding;
    }

    /** The concatenated text the lexical index and the embedder read. */
    public function searchableText(): string
    {
        return trim($this->title.' '.$this->description.' '.implode(' ', $this->keywords));
    }

    public function id(): string
    {
        return $this->id;
    }

    public function type(): SearchType
    {
        return $this->type;
    }

    public function sourceId(): string
    {
        return $this->sourceId;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function description(): string
    {
        return $this->description;
    }

    /** @return list<string> */
    public function keywords(): array
    {
        return $this->keywords;
    }

    public function url(): ?string
    {
        return $this->url;
    }

    public function image(): ?string
    {
        return $this->image;
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function facets(): DocumentFacets
    {
        return $this->facets;
    }

    public function geo(): ?GeoPoint
    {
        return $this->geo;
    }

    public function embedding(): ?Embedding
    {
        return $this->embedding;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
