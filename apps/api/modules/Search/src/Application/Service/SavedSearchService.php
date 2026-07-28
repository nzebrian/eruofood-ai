<?php

declare(strict_types=1);

namespace EruoFood\Search\Application\Service;

use DateTimeImmutable;
use EruoFood\Search\Domain\Enum\SearchType;
use EruoFood\Search\Domain\Enum\SortOption;
use EruoFood\Search\Domain\Exception\SearchNotAuthorized;
use EruoFood\Search\Domain\Exception\SearchNotFound;
use EruoFood\Search\Domain\SavedSearch\SavedSearch;
use EruoFood\Search\Domain\SavedSearch\SavedSearchRepository;
use EruoFood\Search\Domain\ValueObject\SearchFilters;

/** Create, list, delete and re-run a user's saved searches. */
final readonly class SavedSearchService
{
    public function __construct(
        private SavedSearchRepository $repository,
    ) {
    }

    public function save(
        string $userId,
        string $name,
        string $term,
        SearchType $type,
        SearchFilters $filters,
        SortOption $sort,
    ): SavedSearch {
        $saved = SavedSearch::create(
            $this->repository->nextIdentity(),
            $userId,
            $name,
            $term,
            $type,
            $filters,
            $sort,
            new DateTimeImmutable(),
        );
        $this->repository->save($saved);

        return $saved;
    }

    /**
     * @return list<SavedSearch>
     */
    public function forUser(string $userId): array
    {
        return $this->repository->forUser($userId);
    }

    public function delete(string $userId, string $id): void
    {
        $saved = $this->repository->findById($id) ?? throw SearchNotFound::of('saved search', $id);
        if ($saved->userId() !== $userId) {
            throw new SearchNotAuthorized('You may only delete your own saved searches.');
        }
        $this->repository->delete($id);
    }

    public function get(string $userId, string $id): SavedSearch
    {
        $saved = $this->repository->findById($id) ?? throw SearchNotFound::of('saved search', $id);
        if ($saved->userId() !== $userId) {
            throw new SearchNotAuthorized('You may only access your own saved searches.');
        }

        return $saved;
    }
}
