<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Domain\Notification;

/** Read port for delivery analytics (counts by status / channel). */
interface DeliveryStatsRepository
{
    /** @return array<string, int> status value => count */
    public function countByStatus(): array;

    /** @return array<string, int> channel value => count */
    public function countByChannel(): array;
}
