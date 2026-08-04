<?php

declare(strict_types=1);

namespace EruoFood\Support\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use EruoFood\Support\Domain\Ticket\SupportStatsRepository;
use EruoFood\Support\Infrastructure\Persistence\Eloquent\Model\TicketModel;
use Illuminate\Support\Facades\DB;

/**
 * Reporting queries for the admin dashboards. Queue counts are aggregated in
 * SQL; the SLA and agent-performance reports fetch the (time-bounded) ticket
 * rows and compute time-based metrics in PHP so the maths is identical on
 * Postgres and sqlite (no DB-specific date arithmetic).
 */
final class EloquentSupportStatsRepository implements SupportStatsRepository
{
    public function queueCounts(): array
    {
        /** @var array<string, int> $rows */
        $rows = TicketModel::query()->selectRaw('status, count(*) as c')
            ->groupBy('status')->toBase()->pluck('c', 'status')->map(fn ($v): int => (int) $v)->all();

        return $rows;
    }

    public function slaReport(int $days): array
    {
        $rows = $this->recentRows($days);
        $now = new DateTimeImmutable();

        $total = count($rows);
        $resolved = 0;
        $frBreached = 0;
        $resBreached = 0;
        $frMinutesSum = 0.0;
        $frMinutesCount = 0;

        foreach ($rows as $row) {
            $created = $this->dt($row->created_at);
            $frDue = $this->dt($row->first_response_due_at);
            $resDue = $this->dt($row->resolution_due_at);
            $frAt = $this->dt($row->first_responded_at);
            $resAt = $this->dt($row->resolved_at);

            if ($resAt !== null) {
                $resolved++;
            }
            if ($this->breached($frDue, $frAt, $now)) {
                $frBreached++;
            }
            if ($this->breached($resDue, $resAt, $now)) {
                $resBreached++;
            }
            if ($frAt !== null && $created !== null) {
                $frMinutesSum += ($frAt->getTimestamp() - $created->getTimestamp()) / 60.0;
                $frMinutesCount++;
            }
        }

        $breached = $frBreached + $resBreached;

        return [
            'total' => $total,
            'resolved' => $resolved,
            'first_response_breached' => $frBreached,
            'resolution_breached' => $resBreached,
            'breach_rate' => $total > 0 ? round($breached / $total, 4) : 0.0,
            'avg_first_response_minutes' => $frMinutesCount > 0 ? round($frMinutesSum / $frMinutesCount, 1) : 0.0,
        ];
    }

    public function agentPerformance(int $days): array
    {
        $rows = $this->recentRows($days);

        /** @var array<string, array{assigned: int, resolved: int, fr_sum: float, fr_count: int}> $byAgent */
        $byAgent = [];
        foreach ($rows as $row) {
            $agent = $row->assignee_id;
            if (! is_string($agent) || $agent === '') {
                continue;
            }
            $byAgent[$agent] ??= ['assigned' => 0, 'resolved' => 0, 'fr_sum' => 0.0, 'fr_count' => 0];
            $byAgent[$agent]['assigned']++;
            if ($row->resolved_at !== null) {
                $byAgent[$agent]['resolved']++;
            }
            $created = $this->dt($row->created_at);
            $frAt = $this->dt($row->first_responded_at);
            if ($frAt !== null && $created !== null) {
                $byAgent[$agent]['fr_sum'] += ($frAt->getTimestamp() - $created->getTimestamp()) / 60.0;
                $byAgent[$agent]['fr_count']++;
            }
        }

        $out = [];
        foreach ($byAgent as $agentId => $stats) {
            $out[] = [
                'agent_id' => $agentId,
                'assigned' => $stats['assigned'],
                'resolved' => $stats['resolved'],
                'avg_first_response_minutes' => $stats['fr_count'] > 0 ? round($stats['fr_sum'] / $stats['fr_count'], 1) : 0.0,
            ];
        }
        usort($out, static fn (array $a, array $b): int => $b['resolved'] <=> $a['resolved']);

        return $out;
    }

    /**
     * @return list<TicketModel>
     */
    private function recentRows(int $days): array
    {
        $since = (new DateTimeImmutable('-'.max(1, $days).' days'))->format('Y-m-d H:i:s');

        /** @var list<TicketModel> $rows */
        $rows = TicketModel::query()->where('created_at', '>=', $since)->get()->all();

        return $rows;
    }

    private function breached(?DateTimeImmutable $due, ?DateTimeImmutable $met, DateTimeImmutable $now): bool
    {
        if ($due === null) {
            return false;
        }
        if ($met === null) {
            return $now > $due;
        }

        return $met > $due;
    }

    private function dt(mixed $value): ?DateTimeImmutable
    {
        return $value !== null ? DateTimeImmutable::createFromInterface($value) : null;
    }
}
