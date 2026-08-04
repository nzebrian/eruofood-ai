<?php

declare(strict_types=1);

namespace EruoFood\Support\Domain\Ticket;

/**
 * Read-side port for the agent/SLA dashboards. Kept separate from the write
 * repository so reporting queries evolve independently of the aggregate store.
 */
interface SupportStatsRepository
{
    /**
     * Ticket counts by status (the queue overview).
     *
     * @return array<string, int>
     */
    public function queueCounts(): array;

    /**
     * SLA compliance over the last N days.
     *
     * @return array{total: int, resolved: int, first_response_breached: int, resolution_breached: int, breach_rate: float, avg_first_response_minutes: float}
     */
    public function slaReport(int $days): array;

    /**
     * Per-agent performance over the last N days.
     *
     * @return list<array{agent_id: string, assigned: int, resolved: int, avg_first_response_minutes: float}>
     */
    public function agentPerformance(int $days): array;
}
