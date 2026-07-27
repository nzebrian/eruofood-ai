<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Application\DTO;

/**
 * Aggregated delivery figures for the admin analytics dashboard: totals by
 * status and by channel.
 */
final readonly class DeliveryReport
{
    /**
     * @param array<string, int> $byStatus
     * @param array<string, int> $byChannel
     */
    public function __construct(
        public int $total,
        public array $byStatus,
        public array $byChannel,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['total' => $this->total, 'by_status' => $this->byStatus, 'by_channel' => $this->byChannel];
    }
}
