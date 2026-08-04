<?php

declare(strict_types=1);

namespace EruoFood\Search\Domain\SavedSearch;

/** Persistence port for the {@see SavedSearch} aggregate. */
interface SavedSearchRepository
{
    public function nextIdentity(): string;

    public function findById(string $id): ?SavedSearch;

    /** @return list<SavedSearch> */
    public function forUser(string $userId): array;

    public function save(SavedSearch $savedSearch): void;

    public function delete(string $id): void;
}
