<?php

declare(strict_types=1);

namespace EruoFood\Support\Application\Service;

use EruoFood\Support\Domain\Csat\CsatRepository;
use EruoFood\Support\Domain\Csat\CsatSummary;
use EruoFood\Support\Domain\Ticket\SupportStatsRepository;

/**
 * The admin support dashboards: queue overview, SLA compliance report, per-agent
 * team performance and the CSAT summary.
 */
final readonly class SupportAnalyticsService
{
    public function __construct(
        private SupportStatsRepository $stats,
        private CsatRepository $csat,
    ) {
    }

    /** @return array<string, int> */
    public function queue(): array
    {
        return $this->stats->queueCounts();
    }

    /**
     * @return array{total: int, resolved: int, first_response_breached: int, resolution_breached: int, breach_rate: float, avg_first_response_minutes: float}
     */
    public function slaReport(int $days): array
    {
        return $this->stats->slaReport($days);
    }

    /**
     * @return list<array{agent_id: string, assigned: int, resolved: int, avg_first_response_minutes: float}>
     */
    public function agentPerformance(int $days): array
    {
        return $this->stats->agentPerformance($days);
    }

    public function csat(int $days): CsatSummary
    {
        return $this->csat->summary($days);
    }
}
