<?php

declare(strict_types=1);

namespace EruoFood\Nutrition\Application\Service;

use EruoFood\Nutrition\Application\Input\ProgressInput;
use EruoFood\Nutrition\Domain\Progress\ProgressEntry;
use EruoFood\Nutrition\Domain\Progress\ProgressRepository;
use EruoFood\Shared\Domain\Clock;

/** Progress tracking: record body-weight measurements and read history. */
final readonly class ProgressService
{
    public function __construct(
        private ProgressRepository $progress,
        private Clock $clock,
    ) {
    }

    public function record(string $userId, ProgressInput $input): ProgressEntry
    {
        $entry = ProgressEntry::create(
            id: $this->progress->nextIdentity(),
            userId: $userId,
            date: $input->date,
            weightKg: $input->weightKg,
            note: $input->note,
            recordedAt: $this->clock->now(),
        );
        $this->progress->save($entry);

        return $entry;
    }

    /**
     * @return list<ProgressEntry>
     */
    public function history(string $userId, int $limit = 90): array
    {
        return $this->progress->forUser($userId, max(1, min(365, $limit)));
    }

    public function latest(string $userId): ?ProgressEntry
    {
        return $this->progress->latest($userId);
    }
}
