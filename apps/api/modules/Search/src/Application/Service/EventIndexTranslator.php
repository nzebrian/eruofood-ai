<?php

declare(strict_types=1);

namespace EruoFood\Search\Application\Service;

use EruoFood\Search\Infrastructure\Job\ReindexDocumentJob;
use EruoFood\Shared\Domain\DomainEvent;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;

/**
 * The decoupling bridge for indexing: turns published {@see DomainEvent}s from
 * any context into reindex requests, driven purely by the config event-map. It
 * never imports another context's event classes — it keys off the event's stable
 * name and reads the source id from the event's public properties via reflection.
 * This is why no business module searches or indexes directly: they publish a
 * "published/verified" event, and Search reacts by reindexing that item.
 *
 * ## M38-QUEUE-001 — it enqueues, it does not index
 *
 * This used to call `SearchIndexManager::reindex()` inline, which meant the
 * request that published a food item also hydrated the source, generated the
 * embedding, upserted the document, wrote the vector column and flushed the
 * cache before it could return.
 *
 * Now it dispatches {@see ReindexDocumentJob} onto the dedicated search queue
 * and returns. `dispatchSync()` is deliberately not used — that would put the
 * work straight back on the request thread while looking asynchronous.
 *
 * The synchronous path survives only behind `search.async_indexing = false`,
 * for local debugging and controlled rollback. It is not a test escape hatch:
 * `SearchAsyncIndexingTest` asserts the shipped default is asynchronous, so
 * disabling it fails the suite instead of hiding the defect.
 */
final readonly class EventIndexTranslator
{
    /**
     * @param array<string, array{type: string, id_field: string}> $eventMap
     * @param list<int> $backoff
     */
    public function __construct(
        private SearchIndexManager $indexManager,
        private array $eventMap,
        private BusDispatcher $bus,
        private bool $async = true,
        private string $queue = 'search',
        private int $tries = 5,
        private int $timeout = 120,
        private array $backoff = [10, 30, 120, 300],
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

        if (! $this->async) {
            $this->indexManager->reindex($entry['type'], $sourceId);

            return;
        }

        $job = new ReindexDocumentJob($entry['type'], $sourceId);
        $job->tries = $this->tries;
        $job->timeout = $this->timeout;
        $job->backoffSchedule = $this->backoff;
        $job->onQueue($this->queue);

        $this->bus->dispatch($job);
    }
}
