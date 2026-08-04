<?php

declare(strict_types=1);

namespace EruoFood\Search\Domain\SavedSearch;

use DateTimeImmutable;
use EruoFood\Search\Domain\Enum\SearchType;
use EruoFood\Search\Domain\Enum\SortOption;
use EruoFood\Search\Domain\ValueObject\SearchFilters;

/**
 * A user's saved query — a named search they can re-run. Owned by the user and
 * referenced by Identity user id (soft reference).
 */
final class SavedSearch
{
    private function __construct(
        private readonly string $id,
        private readonly string $userId,
        private string $name,
        private readonly string $term,
        private readonly SearchType $type,
        private readonly SearchFilters $filters,
        private readonly SortOption $sort,
        private readonly DateTimeImmutable $createdAt,
    ) {
    }

    public static function create(
        string $id,
        string $userId,
        string $name,
        string $term,
        SearchType $type,
        SearchFilters $filters,
        SortOption $sort,
        DateTimeImmutable $now,
    ): self {
        return new self($id, $userId, $name, $term, $type, $filters, $sort, $now);
    }

    public static function reconstitute(
        string $id,
        string $userId,
        string $name,
        string $term,
        SearchType $type,
        SearchFilters $filters,
        SortOption $sort,
        DateTimeImmutable $createdAt,
    ): self {
        return new self($id, $userId, $name, $term, $type, $filters, $sort, $createdAt);
    }

    public function rename(string $name): void
    {
        $this->name = $name;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function userId(): string
    {
        return $this->userId;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function term(): string
    {
        return $this->term;
    }

    public function type(): SearchType
    {
        return $this->type;
    }

    public function filters(): SearchFilters
    {
        return $this->filters;
    }

    public function sort(): SortOption
    {
        return $this->sort;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
