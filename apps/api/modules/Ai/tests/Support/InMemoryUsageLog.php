<?php

declare(strict_types=1);

namespace EruoFood\Ai\Tests\Support;

use EruoFood\Ai\Domain\Usage\AiUsageLog;
use EruoFood\Ai\Domain\Usage\AiUsageLogRepository;
use EruoFood\Ai\Domain\Usage\UsageSummary;

/** In-memory usage ledger for asserting what the gateway recorded. */
final class InMemoryUsageLog implements AiUsageLogRepository
{
    /** @var list<AiUsageLog> */
    public array $logs = [];

    public function nextIdentity(): string
    {
        return 'log-'.count($this->logs);
    }

    public function record(AiUsageLog $log): void
    {
        $this->logs[] = $log;
    }

    public function summaryForUser(string $userId, int $sinceDays): UsageSummary
    {
        return new UsageSummary(count($this->logs), 0, 0, 0.0, 0);
    }

    public function countForUserSince(string $userId, int $sinceSeconds): int
    {
        return count($this->logs);
    }

    public function last(): ?AiUsageLog
    {
        return $this->logs[array_key_last($this->logs)] ?? null;
    }
}
