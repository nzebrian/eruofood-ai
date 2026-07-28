<?php

declare(strict_types=1);

namespace EruoFood\Analytics\Application\Service;

use EruoFood\Analytics\Domain\Report\Report;
use EruoFood\Analytics\Domain\Report\ScheduledReport;

/** Maps Analytics aggregates to API-shaped arrays. */
final readonly class AnalyticsPresenter
{
    /** @return array<string, mixed> */
    public function report(Report $r): array
    {
        return [
            'id' => $r->id(),
            'key' => $r->key(),
            'title' => $r->title(),
            'range' => $r->range()->toArray(),
            'columns' => $r->columns(),
            'rows' => $r->rows(),
            'status' => $r->status()->value,
            'generated_at' => $r->generatedAt()->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public function scheduledReport(ScheduledReport $s): array
    {
        return [
            'id' => $s->id(),
            'name' => $s->name(),
            'report_key' => $s->reportKey(),
            'cadence' => $s->cadence()->value,
            'format' => $s->format()->value,
            'recipients' => $s->recipients(),
            'active' => $s->isActive(),
            'next_run_at' => $s->nextRunAt()->format(DATE_ATOM),
            'last_run_at' => $s->lastRunAt()?->format(DATE_ATOM),
        ];
    }
}
