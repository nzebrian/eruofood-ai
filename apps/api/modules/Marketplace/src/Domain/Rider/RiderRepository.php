<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Domain\Rider;

interface RiderRepository
{
    public function nextIdentity(): string;

    public function findById(string $id): ?Rider;

    public function findByUser(string $userId): ?Rider;

    /**
     * Available riders, optionally nearest first to a point (handled by the repo).
     *
     * @return list<Rider>
     */
    public function available(int $limit = 20): array;

    public function save(Rider $rider): void;
}
