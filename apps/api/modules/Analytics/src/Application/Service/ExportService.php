<?php

declare(strict_types=1);

namespace EruoFood\Analytics\Application\Service;

use EruoFood\Analytics\Application\DTO\ExportResult;
use EruoFood\Analytics\Application\Port\ReportExporter;
use EruoFood\Analytics\Domain\Enum\ExportFormat;
use EruoFood\Analytics\Domain\Exception\AnalyticsNotFound;
use EruoFood\Analytics\Domain\Report\ReportRepository;
use EruoFood\Analytics\Domain\ValueObject\DateRange;

/** Generates + serialises reports for download (CSV native; XLSX/PDF arch-ready). */
final readonly class ExportService
{
    public function __construct(
        private ReportRepository $reports,
        private ReportGenerator $generator,
        private ReportExporter $exporter,
    ) {
    }

    public function exportExisting(string $reportId, ExportFormat $format): ExportResult
    {
        $report = $this->reports->findById($reportId) ?? throw AnalyticsNotFound::of('report', $reportId);

        return $this->exporter->export($report, $format);
    }

    public function generateAndExport(string $key, DateRange $range, ExportFormat $format): ExportResult
    {
        return $this->exporter->export($this->generator->generate($key, $range), $format);
    }
}
