<?php

declare(strict_types=1);

namespace EruoFood\Analytics\Interface\Http\Controller;

use EruoFood\Analytics\Application\Service\AnalyticsPresenter;
use EruoFood\Analytics\Application\Service\ExportService;
use EruoFood\Analytics\Application\Service\ReportGenerator;
use EruoFood\Analytics\Domain\Enum\ExportFormat;
use EruoFood\Analytics\Domain\Exception\AnalyticsNotFound;
use EruoFood\Analytics\Domain\Report\Report;
use EruoFood\Analytics\Domain\Report\ReportRepository;
use EruoFood\Analytics\Interface\Http\Concerns\ResolvesDateRange;
use EruoFood\Analytics\Interface\Http\Concerns\RespondsWithData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Generate, list and export analytics reports. */
final readonly class ReportController
{
    use RespondsWithData;
    use ResolvesDateRange;

    public function __construct(
        private ReportGenerator $generator,
        private ExportService $exports,
        private ReportRepository $reports,
        private AnalyticsPresenter $presenter,
        private int $defaultDays,
    ) {
    }

    public function catalogue(): JsonResponse
    {
        return $this->data(['reports' => $this->generator->catalogue()]);
    }

    public function recent(Request $request): JsonResponse
    {
        $page = $this->reports->recent((int) $request->integer('page', 1), (int) $request->integer('per_page', 20));

        return $this->paginated($page, fn (Report $r): array => $this->presenter->report($r));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['key' => ['required', 'string', 'max:60']]);
        $report = $this->generator->generate((string) $data['key'], $this->resolveRange($request, $this->defaultDays));

        return $this->data($this->presenter->report($report), 201);
    }

    public function show(string $id): JsonResponse
    {
        $report = $this->reports->findById($id) ?? throw AnalyticsNotFound::of('report', $id);

        return $this->data($this->presenter->report($report));
    }

    public function export(Request $request, string $id): StreamedResponse
    {
        $format = ExportFormat::tryFrom((string) $request->string('format', 'csv')) ?? ExportFormat::Csv;
        $result = $this->exports->exportExisting($id, $format);

        // Streamed download: memory-efficient for large exports and the standard
        // shape for file responses.
        return response()->streamDownload(
            static function () use ($result): void {
                echo $result->content;
            },
            $result->filename,
            [
                'Content-Type' => $result->mimeType,
            ],
        );
    }
}
