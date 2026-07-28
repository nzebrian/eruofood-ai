<?php

declare(strict_types=1);

namespace EruoFood\Analytics\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use EruoFood\Analytics\Domain\Enum\ReportStatus;
use EruoFood\Analytics\Domain\Report\Report;
use EruoFood\Analytics\Domain\Report\ReportRepository;
use EruoFood\Analytics\Domain\ValueObject\DateRange;
use EruoFood\Analytics\Infrastructure\Persistence\Eloquent\Model\ReportModel;
use EruoFood\Shared\Domain\Paginated;
use Illuminate\Support\Str;

final class EloquentReportRepository implements ReportRepository
{
    public function nextIdentity(): string
    {
        return (string) Str::orderedUuid();
    }

    public function findById(string $id): ?Report
    {
        $m = ReportModel::query()->find($id);

        return $m !== null ? $this->toDomain($m) : null;
    }

    public function recent(int $page, int $perPage): Paginated
    {
        $paginator = ReportModel::query()->orderByDesc('generated_at')->paginate(perPage: $perPage, page: $page);

        return new Paginated(
            array_map(fn (ReportModel $m): Report => $this->toDomain($m), $paginator->items()),
            $paginator->total(),
            $page,
            $perPage,
        );
    }

    public function save(Report $report): void
    {
        $model = ReportModel::query()->find($report->id()) ?? new ReportModel();
        $model->id = $report->id();
        $model->key = $report->key();
        $model->title = $report->title();
        $model->range_from = $report->range()->fromDate();
        $model->range_to = $report->range()->toDate();
        $model->columns = $report->columns();
        $model->rows = $report->rows();
        $model->status = $report->status()->value;
        $model->generated_at = $report->generatedAt();
        $model->save();
    }

    private function toDomain(ReportModel $m): Report
    {
        return Report::reconstitute(
            id: $m->id,
            key: $m->key,
            title: $m->title,
            range: new DateRange(new DateTimeImmutable((string) $m->range_from), new DateTimeImmutable((string) $m->range_to)),
            columns: array_map('strval', $m->columns ?? []),
            rows: $m->rows ?? [],
            status: ReportStatus::from($m->status),
            generatedAt: DateTimeImmutable::createFromInterface($m->generated_at),
        );
    }
}
