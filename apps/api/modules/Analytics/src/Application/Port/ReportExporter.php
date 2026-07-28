<?php

declare(strict_types=1);

namespace EruoFood\Analytics\Application\Port;

use EruoFood\Analytics\Application\DTO\ExportResult;
use EruoFood\Analytics\Domain\Enum\ExportFormat;
use EruoFood\Analytics\Domain\Report\Report;

/**
 * Serialises a generated {@see Report} to a downloadable file. Adapters cover
 * CSV (native), and architecture-ready XLSX & PDF, behind this one port so the
 * export/scheduled-report flows never depend on a specific format library.
 */
interface ReportExporter
{
    public function export(Report $report, ExportFormat $format): ExportResult;
}
