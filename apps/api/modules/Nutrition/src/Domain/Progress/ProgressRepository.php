<?php

declare(strict_types=1);

namespace EruoFood\Nutrition\Domain\Progress;

/** Persistence port for progress-tracking measurements (Repository Pattern). */
interface ProgressRepository
{
    public function nextIdentity(): string;

    /**
     * A user's measurements, newest first (optionally limited).
     *
     * @return list<ProgressEntry>
     */
    public function forUser(string $userId, int $limit = 90): array;

    public function latest(string $userId): ?ProgressEntry;

    public function save(ProgressEntry $entry): void;
}
