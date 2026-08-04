<?php

declare(strict_types=1);

namespace EruoFood\Ai\Domain\Usage;

/** Persistence port for the AI usage/cost ledger (Repository Pattern). */
interface AiUsageLogRepository
{
    public function nextIdentity(): string;

    public function record(AiUsageLog $log): void;

    /** Rolling usage/cost totals for a user across the given trailing days. */
    public function summaryForUser(string $userId, int $sinceDays): UsageSummary;

    /** Number of requests a user has made within the trailing window (for quotas). */
    public function countForUserSince(string $userId, int $sinceSeconds): int;
}
