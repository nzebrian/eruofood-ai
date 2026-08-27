<?php

declare(strict_types=1);

namespace EruoFood\Search\Infrastructure\Job;

use EruoFood\Search\Application\Service\SearchIndexManager;
use EruoFood\Search\Domain\Observability\IndexFailure;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reindex one document, off the request thread (M38-QUEUE-001).
 *
 * ## What this moves off the publishing request
 *
 * Before M38 `DomainEventSubscriber` registered plain closures, so publishing a
 * food item did all of this inline, in the HTTP request that published it:
 * hydrate the source row, generate the embedding, upsert the document, write
 * the pgvector column, and flush the entire application cache. A slow or
 * failing index made publishing slow or failing. There was no `ShouldQueue`
 * anywhere in the Search module.
 *
 * ## Why duplicate delivery is safe
 *
 * Indexing is idempotent by construction: the document id is deterministic
 * (`"<type>:<sourceId>"`), the write is an upsert, and the embedding is a pure
 * function of the source text. Running this twice converges on the same row.
 *
 * `ShouldBeUnique` on `type:sourceId` additionally collapses a burst of events
 * for the same document into one job while it is queued — an optimisation, not
 * the correctness argument. If the unique lock is lost, a second run is still
 * safe.
 */
final class ReindexDocumentJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Bounded. Set from config at dispatch time by
     * {@see \EruoFood\Search\Application\Service\EventIndexTranslator}.
     */
    public int $tries = 5;

    public int $timeout = 120;

    /** @var list<int> */
    public array $backoffSchedule = [10, 30, 120, 300];

    public function __construct(
        public readonly string $type,
        public readonly string $sourceId,
    ) {
    }

    /**
     * Deterministic, and deliberately the same identity the index uses for the
     * document itself.
     */
    public function uniqueId(): string
    {
        return $this->type.':'.$this->sourceId;
    }

    /**
     * How long the unique lock survives if a worker dies without releasing it.
     * Longer than the timeout, so a killed job cannot block its own retry.
     */
    public function uniqueFor(): int
    {
        return $this->timeout * 3;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return $this->backoffSchedule;
    }

    public function handle(SearchIndexManager $indexManager): void
    {
        // Any throw propagates: the queue records the attempt, applies the
        // backoff, and after `tries` puts it in failed_jobs. That visibility is
        // the point — there is no catch here, because swallowing would restore
        // exactly the silence M38-OBS-001 exists to remove.
        $indexManager->reindex($this->type, $this->sourceId);
    }

    /**
     * Permanent failure. `failed_jobs` already holds the payload and exception;
     * this adds the stable code an alert rule can match on.
     */
    public function failed(?Throwable $e): void
    {
        Log::error(IndexFailure::JobExhausted->value, [
            'code' => IndexFailure::JobExhausted->value,
            'document_type' => $this->type,
            // The source id only. No title, description or document body is
            // ever logged — indexed content can carry data the log store is
            // not cleared for.
            'source_id' => $this->sourceId,
            'attempts' => $this->attempts(),
            'exception' => $e?->getMessage(),
        ]);
    }
}
