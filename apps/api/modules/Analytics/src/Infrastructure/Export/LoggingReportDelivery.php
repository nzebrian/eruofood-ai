<?php

declare(strict_types=1);

namespace EruoFood\Analytics\Infrastructure\Export;

use EruoFood\Analytics\Application\DTO\ExportResult;
use EruoFood\Analytics\Application\Port\ReportDelivery;
use Psr\Log\LoggerInterface;

/**
 * The default scheduled-report delivery — logs the send (offline-safe). A real
 * mailer or the Notifications context can replace it behind the ReportDelivery
 * port to email the attachment to recipients.
 */
final readonly class LoggingReportDelivery implements ReportDelivery
{
    public function __construct(private LoggerInterface $log)
    {
    }

    public function deliver(array $recipients, string $subject, ExportResult $export): void
    {
        $this->log->info('analytics.report.delivered', [
            'recipients' => $recipients,
            'subject' => $subject,
            'filename' => $export->filename,
            'bytes' => strlen($export->content),
        ]);
    }
}
