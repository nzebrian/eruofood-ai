<?php

declare(strict_types=1);

namespace EruoFood\Analytics\Domain\Metric;

use DateTimeImmutable;

/** Append-only persistence port for the raw {@see AnalyticsEvent} collection. */
interface AnalyticsEventRepository
{
    public function nextIdentity(): string;

    public function append(AnalyticsEvent $event): void;

    public function countSince(DateTimeImmutable $since): int;
}
