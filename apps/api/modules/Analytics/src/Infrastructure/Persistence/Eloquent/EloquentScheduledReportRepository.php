<?php

declare(strict_types=1);

namespace EruoFood\Analytics\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use EruoFood\Analytics\Domain\Enum\ExportFormat;
use EruoFood\Analytics\Domain\Enum\ReportCadence;
use EruoFood\Analytics\Domain\Report\ScheduledReport;
use EruoFood\Analytics\Domain\Report\ScheduledReportRepository;
use EruoFood\Analytics\Infrastructure\Persistence\Eloquent\Model\ScheduledReportModel;
use Illuminate\Support\Str;

final class EloquentScheduledReportRepository implements ScheduledReportRepository
{
    public function nextIdentity(): string
    {
        return (string) Str::orderedUuid();
    }

    public function findById(string $id): ?ScheduledReport
    {
        $m = ScheduledReportModel::query()->find($id);

        return $m !== null ? $this->toDomain($m) : null;
    }

    public function all(): array
    {
        return array_values(array_map(
            fn (ScheduledReportModel $m): ScheduledReport => $this->toDomain($m),
            ScheduledReportModel::query()->orderByDesc('created_at')->get()->all(),
        ));
    }

    public function due(DateTimeImmutable $now): array
    {
        return array_values(array_map(
            fn (ScheduledReportModel $m): ScheduledReport => $this->toDomain($m),
            ScheduledReportModel::query()->where('active', true)
                ->where('next_run_at', '<=', $now->format('Y-m-d H:i:s'))->get()->all(),
        ));
    }

    public function save(ScheduledReport $report): void
    {
        $model = ScheduledReportModel::query()->find($report->id()) ?? new ScheduledReportModel();
        $model->id = $report->id();
        $model->name = $report->name();
        $model->report_key = $report->reportKey();
        $model->cadence = $report->cadence()->value;
        $model->format = $report->format()->value;
        $model->recipients = $report->recipients();
        $model->active = $report->isActive();
        $model->next_run_at = $report->nextRunAt();
        $model->last_run_at = $report->lastRunAt();
        $model->created_at = $report->createdAt();
        $model->save();
    }

    private function toDomain(ScheduledReportModel $m): ScheduledReport
    {
        return ScheduledReport::reconstitute(
            id: $m->id,
            name: $m->name,
            reportKey: $m->report_key,
            cadence: ReportCadence::from($m->cadence),
            format: ExportFormat::from($m->format),
            recipients: array_map('strval', $m->recipients ?? []),
            active: $m->active,
            nextRunAt: DateTimeImmutable::createFromInterface($m->next_run_at),
            lastRunAt: $m->last_run_at !== null ? DateTimeImmutable::createFromInterface($m->last_run_at) : null,
            createdAt: DateTimeImmutable::createFromInterface($m->created_at),
        );
    }
}
