<?php

declare(strict_types=1);

namespace EruoFood\Analytics\Application\Port;

use EruoFood\Analytics\Application\DTO\ExportResult;

/**
 * Delivers a scheduled report export to its recipients (email). A port so the
 * Notifications context or a mailer can be plugged in; the default logs.
 *
 * @phpstan-type Recipients list<string>
 */
interface ReportDelivery
{
    /**
     * @param list<string> $recipients
     */
    public function deliver(array $recipients, string $subject, ExportResult $export): void;
}
