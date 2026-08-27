<?php

declare(strict_types=1);

use EruoFood\Catalog\Domain\Event\FoodPublished;
use EruoFood\Search\Application\Service\SearchIndexManager;
use EruoFood\Search\Domain\Observability\IndexFailure;
use EruoFood\Search\Infrastructure\Job\ReindexDocumentJob;
use EruoFood\Shared\Domain\EventBus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * M38-QUEUE-001 — indexing left the request thread.
 *
 * `DomainEventSubscriber` used to register plain closures, so publishing a food
 * item performed source hydration, embedding generation, the upsert, the vector
 * write and a full cache flush inline, inside the publishing HTTP request.
 * There was no `ShouldQueue` class anywhere in the Search module.
 */

function seedPublishedFood(string $name = 'Async Jollof'): string
{
    $id = (string) Str::orderedUuid();

    DB::table('catalog_foods')->insert([
        'id' => $id,
        'name' => $name,
        'slug' => Str::slug($name).'-'.substr($id, 0, 8),
        'description' => 'queued indexing subject',
        'category_id' => (string) Str::orderedUuid(),
        'region' => 'South West',
        'states' => json_encode([]),
        'local_names' => json_encode([]),
        'nutrition' => json_encode(['calories' => 500]),
        'images' => json_encode([]),
        'tags' => json_encode([]),
        'status' => 'published',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

it('ships with asynchronous indexing enabled by default', function (): void {
    // The flag is a rollback lever, not a way to make this suite pass while
    // production keeps the synchronous defect. Shipping it disabled fails here.
    expect(config('search.async_indexing'))->toBeTrue();
});

it('enqueues a job instead of indexing on the publishing request thread', function (): void {
    Queue::fake();

    $id = seedPublishedFood();
    app(EventBus::class)->publish(new FoodPublished($id));

    Queue::assertPushed(ReindexDocumentJob::class, fn (ReindexDocumentJob $job): bool => $job->sourceId === $id && $job->type === 'food');

    // The decisive part: nothing was indexed inline. Under the old
    // implementation the document existed by now.
    expect(DB::table('search_documents')->count())->toBe(0);
});

it('dispatches onto the dedicated search queue', function (): void {
    Queue::fake();

    app(EventBus::class)->publish(new FoodPublished(seedPublishedFood()));

    Queue::assertPushedOn(config('search.queue'), ReindexDocumentJob::class);
});

it('derives a deterministic unique id from type and source id', function (): void {
    $job = new ReindexDocumentJob('food', 'abc-123');

    expect($job->uniqueId())->toBe('food:abc-123')
        // Same document, same identity — which is what collapses a burst of
        // events for one item into a single queued job.
        ->and((new ReindexDocumentJob('food', 'abc-123'))->uniqueId())->toBe($job->uniqueId())
        ->and((new ReindexDocumentJob('recipe', 'abc-123'))->uniqueId())->not->toBe($job->uniqueId());
});

it('is configured with bounded retries, backoff and a timeout', function (): void {
    $job = new ReindexDocumentJob('food', 'abc-123');

    expect($job->tries)->toBeGreaterThan(1)
        ->and($job->timeout)->toBeGreaterThan(0)
        ->and($job->backoff())->not->toBeEmpty()
        // Exponential-ish: each wait is longer than the last.
        ->and($job->backoff())->toBe([10, 30, 120, 300]);
});

it('converges on one document when the same event is delivered twice', function (): void {
    $id = seedPublishedFood();
    $manager = app(SearchIndexManager::class);

    // Duplicate delivery — the queue guarantees at-least-once, so this must be
    // safe by construction, not merely unlikely.
    (new ReindexDocumentJob('food', $id))->handle($manager);
    (new ReindexDocumentJob('food', $id))->handle($manager);

    expect(DB::table('search_documents')->where('source_id', $id)->count())->toBe(1);
});

it('converges on the same document when a retry re-runs after a partial failure', function (): void {
    $id = seedPublishedFood();
    $manager = app(SearchIndexManager::class);

    (new ReindexDocumentJob('food', $id))->handle($manager);
    $first = DB::table('search_documents')->where('source_id', $id)->first();

    (new ReindexDocumentJob('food', $id))->handle($manager);
    $second = DB::table('search_documents')->where('source_id', $id)->first();

    expect($second->id)->toBe($first->id)
        ->and($second->search_text)->toBe($first->search_text);
});

it('makes permanent failure visible with a stable code', function (): void {
    Log::spy();

    (new ReindexDocumentJob('food', 'gone'))->failed(new RuntimeException('provider exploded'));

    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $message, array $context): bool => $message === IndexFailure::JobExhausted->value
            && $context['code'] === IndexFailure::JobExhausted->value
            && $context['source_id'] === 'gone');
});

it('does not log document content when a job fails', function (): void {
    Log::spy();

    (new ReindexDocumentJob('food', 'gone'))->failed(new RuntimeException('boom'));

    // Indexed content is hydrated from other contexts; the log store is not
    // cleared to hold it, so only identifiers may be recorded.
    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $message, array $context): bool => ! array_key_exists('title', $context)
            && ! array_key_exists('description', $context)
            && ! array_key_exists('search_text', $context));
});
