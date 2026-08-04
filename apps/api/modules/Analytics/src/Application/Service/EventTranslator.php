<?php

declare(strict_types=1);

namespace EruoFood\Analytics\Application\Service;

use EruoFood\Analytics\Domain\Enum\AnalyticsCategory;
use EruoFood\Analytics\Domain\Enum\MetricOp;
use EruoFood\Shared\Domain\DomainEvent;

/**
 * The decoupling bridge: turns any published {@see DomainEvent} into an
 * analytics collection call, driven purely by the config event-map. Like the
 * Notifications translator, it keys off the event's stable name and reads
 * numeric values and dimensions from the event's public properties via
 * reflection — never importing another context's event class. This is why no
 * module writes into analytics: they publish events, and this reacts.
 *
 * @phpstan-type MapEntry array{metric: string, category: string, op: string, value_key?: string, dimensions: list<string>}
 */
final readonly class EventTranslator
{
    /**
     * @param array<string, array{metric: string, category: string, op: string, value_key?: string, dimensions: list<string>}> $eventMap
     */
    public function __construct(
        private EventCollectionService $collector,
        private array $eventMap,
    ) {
    }

    public function handle(DomainEvent $event): void
    {
        $entry = $this->eventMap[$event->eventName()] ?? null;
        if ($entry === null) {
            return;
        }

        /** @var array<string, mixed> $vars */
        $vars = get_object_vars($event);
        unset($vars['occurredAt']);

        $op = MetricOp::from($entry['op']);
        $rawValue = 0;
        if ($op === MetricOp::Sum && isset($entry['value_key'], $vars[$entry['value_key']]) && is_numeric($vars[$entry['value_key']])) {
            $rawValue = (int) $vars[$entry['value_key']];
        }

        $dimensions = [];
        foreach ($entry['dimensions'] as $key) {
            if (isset($vars[$key]) && is_scalar($vars[$key]) && (string) $vars[$key] !== '') {
                $dimensions[$this->snake($key)] = (string) $vars[$key];
            }
        }

        $this->collector->collect(
            $event->eventName(),
            $entry['metric'],
            AnalyticsCategory::from($entry['category']),
            $op,
            $rawValue,
            $dimensions,
            $event->occurredAt(),
        );
    }

    private function snake(string $key): string
    {
        return strtolower((string) preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $key));
    }
}
