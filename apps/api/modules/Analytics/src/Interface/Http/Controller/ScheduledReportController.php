<?php

declare(strict_types=1);

namespace EruoFood\Analytics\Interface\Http\Controller;

use EruoFood\Analytics\Application\Service\AnalyticsPresenter;
use EruoFood\Analytics\Application\Service\ScheduledReportService;
use EruoFood\Analytics\Domain\Enum\ExportFormat;
use EruoFood\Analytics\Domain\Enum\ReportCadence;
use EruoFood\Analytics\Domain\Report\ScheduledReport;
use EruoFood\Analytics\Interface\Http\Concerns\RespondsWithData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Scheduled email reports. */
final readonly class ScheduledReportController
{
    use RespondsWithData;

    public function __construct(
        private ScheduledReportService $scheduled,
        private AnalyticsPresenter $presenter,
    ) {
    }

    public function index(): JsonResponse
    {
        return $this->data(array_map(fn (ScheduledReport $s): array => $this->presenter->scheduledReport($s), $this->scheduled->all()));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'report_key' => ['required', 'string', 'max:60'],
            'cadence' => ['required', 'in:daily,weekly,monthly'],
            'format' => ['required', 'in:csv,xlsx,pdf'],
            'recipients' => ['required', 'array', 'min:1'],
            'recipients.*' => ['email'],
        ]);
        $report = $this->scheduled->create(
            (string) $data['name'],
            (string) $data['report_key'],
            ReportCadence::from((string) $data['cadence']),
            ExportFormat::from((string) $data['format']),
            array_values(array_map('strval', $data['recipients'])),
        );

        return $this->data($this->presenter->scheduledReport($report), 201);
    }

    public function deactivate(string $id): JsonResponse
    {
        return $this->data($this->presenter->scheduledReport($this->scheduled->deactivate($id)));
    }

    public function run(): JsonResponse
    {
        return $this->data(['ran' => $this->scheduled->runDue()]);
    }
}
