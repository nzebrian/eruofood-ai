<?php

declare(strict_types=1);

namespace EruoFood\Analytics\Application\Service;

use DateTimeImmutable;
use EruoFood\Analytics\Application\Port\ReportDelivery;
use EruoFood\Analytics\Application\Port\ReportExporter;
use EruoFood\Analytics\Domain\Enum\ExportFormat;
use EruoFood\Analytics\Domain\Enum\ReportCadence;
use EruoFood\Analytics\Domain\Exception\AnalyticsNotFound;
use EruoFood\Analytics\Domain\Report\ScheduledReport;
use EruoFood\Analytics\Domain\Report\ScheduledReportRepository;
use EruoFood\Analytics\Domain\ValueObject\DateRange;

/**
 * Scheduled reports: create/list/deactivate, and the worker entry point that
 * runs the due ones — generating each report, exporting it, emailing it to the
 * recipients, then advancing the schedule.
 */
final readonly class ScheduledReportService
{
    public function __construct(
        private ScheduledReportRepository $scheduled,
        private ReportGenerator $generator,
        private ReportExporter $exporter,
        private ReportDelivery $delivery,
    ) {
    }

    /**
     * @param list<string> $recipients
     */
    public function create(string $name, string $reportKey, ReportCadence $cadence, ExportFormat $format, array $recipients): ScheduledReport
    {
        $report = ScheduledReport::create(
            $this->scheduled->nextIdentity(),
            $name,
            $reportKey,
            $cadence,
            $format,
            $recipients,
            new DateTimeImmutable(),
        );
        $this->scheduled->save($report);

        return $report;
    }

    /** @return list<ScheduledReport> */
    public function all(): array
    {
        return $this->scheduled->all();
    }

    public function deactivate(string $id): ScheduledReport
    {
        $report = $this->scheduled->findById($id) ?? throw AnalyticsNotFound::of('scheduled report', $id);
        $report->deactivate();
        $this->scheduled->save($report);

        return $report;
    }

    /** Run all due scheduled reports (worker entry point). Returns how many ran. */
    public function runDue(): int
    {
        $now = new DateTimeImmutable();
        $count = 0;
        foreach ($this->scheduled->due($now) as $scheduled) {
            $range = $this->rangeFor($scheduled->cadence(), $now);
            $report = $this->generator->generate($scheduled->reportKey(), $range);
            $export = $this->exporter->export($report, $scheduled->format());
            $this->delivery->deliver($scheduled->recipients(), $scheduled->name(), $export);
            $scheduled->markRun($now);
            $this->scheduled->save($scheduled);
            $count++;
        }

        return $count;
    }

    private function rangeFor(ReportCadence $cadence, DateTimeImmutable $now): DateRange
    {
        return DateRange::lastDays(match ($cadence) {
            ReportCadence::Daily => 1,
            ReportCadence::Weekly => 7,
            ReportCadence::Monthly => 30,
        }, $now);
    }
}
