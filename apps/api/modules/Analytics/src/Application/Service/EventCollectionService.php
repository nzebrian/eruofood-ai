<?php

declare(strict_types=1);

namespace EruoFood\Analytics\Application\Service;

use DateTimeImmutable;
use EruoFood\Analytics\Domain\Enum\AnalyticsCategory;
use EruoFood\Analytics\Domain\Enum\MetricOp;
use EruoFood\Analytics\Domain\Metric\AnalyticsEvent;
use EruoFood\Analytics\Domain\Metric\AnalyticsEventRepository;

/**
 * The Event Collection Service — the single entry point for data entering
 * analytics. It appends the raw fact (append-only audit) and feeds the metric
 * projector. No business module calls this directly; the event subscriber does,
 * from published domain events, so analytics is never written to by another
 * context.
 */
final readonly class EventCollectionService
{
    public function __construct(
        private AnalyticsEventRepository $events,
        private MetricProjector $projector,
    ) {
    }

    /**
     * @param array<string, string> $dimensions
     */
    public function collect(
        string $eventName,
        string $metric,
        AnalyticsCategory $category,
        MetricOp $op,
        int $rawValue,
        array $dimensions,
        DateTimeImmutable $occurredAt,
    ): void {
        $valueForSum = $op === MetricOp::Sum ? $rawValue : 0;

        $this->events->append(AnalyticsEvent::record(
            $this->events->nextIdentity(),
            $eventName,
            $category,
            null,
            $valueForSum,
            $dimensions,
            $occurredAt,
        ));

        $this->projector->project($metric, $category->value, $occurredAt, $valueForSum, $dimensions);
    }
}
