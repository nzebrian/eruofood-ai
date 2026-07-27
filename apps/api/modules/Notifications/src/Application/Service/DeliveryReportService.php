<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Application\Service;

use EruoFood\Notifications\Application\DTO\DeliveryReport;
use EruoFood\Notifications\Domain\Notification\DeliveryStatsRepository;

/** Aggregated delivery analytics for the admin dashboard. */
final readonly class DeliveryReportService
{
    public function __construct(private DeliveryStatsRepository $stats)
    {
    }

    public function report(): DeliveryReport
    {
        $byStatus = $this->stats->countByStatus();
        $byChannel = $this->stats->countByChannel();

        return new DeliveryReport(array_sum($byStatus), $byStatus, $byChannel);
    }
}
