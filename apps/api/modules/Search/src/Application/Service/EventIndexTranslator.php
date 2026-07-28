<?php

declare(strict_types=1);

namespace EruoFood\Search\Application\Service;

use EruoFood\Shared\Domain\DomainEvent;

/**
 * The decoupling bridge for indexing: turns published {@see DomainEvent}s from
 * any context into reindex requests, driven purely by the config event-map. It
 * never imports another context's event classes — it keys off the event's stable
 * name and reads the source id from the event's public properties via reflection.
 * This is why no business module searches or indexes directly: they publish a
 * "published/verified" event, and Search reacts by reindexing that item.
 */
final readonly class EventIndexTranslator
{
    /**
     * @param array<string, array{type: string, id_field: string}> $eventMap
     */
    public function __construct(
        private SearchIndexManager $indexManager,
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
        $sourceId = $vars[$entry['id_field']] ?? null;
        if (! is_string($sourceId) || $sourceId === '') {
            return;
        }

        $this->indexManager->reindex($entry['type'], $sourceId);
    }
}
