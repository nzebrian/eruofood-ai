<?php

declare(strict_types=1);

use EruoFood\Search\Application\Port\EmbeddingGenerator;
use EruoFood\Search\Application\Port\SearchCache;
use EruoFood\Search\Application\Port\SourceDocumentProvider;
use EruoFood\Search\Application\Service\SearchIndexManager;
use EruoFood\Search\Domain\Document\SearchDocument;
use EruoFood\Search\Domain\Document\SearchIndexRepository;
use EruoFood\Search\Domain\Observability\IndexFailure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;

uses(RefreshDatabase::class);

/**
 * M38-OBS-001 — indexing failures are visible.
 *
 * `SearchIndexManager::reindex()` used to hit a bare `return;` on an unknown
 * document type and on a missing source row. No log, no counter, no trace. An
 * indexer that had stopped working looked exactly like one with nothing to do,
 * and `admin/failed` was no help — it reports zero-RESULT search terms, which
 * is a different thing entirely.
 */

/** Collects log records so a test can assert on the codes emitted. */
function recordingLogger(array &$records): LoggerInterface
{
    return new class ($records) extends \Psr\Log\AbstractLogger {
        public function __construct(private array &$records)
        {
        }

        public function log($level, $message, array $context = []): void
        {
            $this->records[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
        }
    };
}

/** A provider whose behaviour each test chooses. */
function scriptedProvider(callable $fetch): SourceDocumentProvider
{
    return new class ($fetch) implements SourceDocumentProvider {
        public function __construct(private $fetch)
        {
        }

        public function fetch(string $sourceId): ?SearchDocument
        {
            return ($this->fetch)($sourceId);
        }

        public function allIds(): array
        {
            return [];
        }

        public function type(): string
        {
            return 'food';
        }
    };
}

function managerWith(array $providers, array &$records): SearchIndexManager
{
    return new SearchIndexManager(
        app(SearchIndexRepository::class),
        app(EmbeddingGenerator::class),
        app(SearchCache::class),
        $providers,
        recordingLogger($records),
    );
}

it('reports an unknown document type instead of returning in silence', function (): void {
    $records = [];
    managerWith([], $records)->reindex('not_a_type', 'abc');

    $codes = array_column($records, 'message');

    expect($codes)->toContain(IndexFailure::UnknownType->value)
        ->and($records[0]['level'])->toBe('error')
        ->and($records[0]['context']['document_type'])->toBe('not_a_type');
});

it('reports a missing source document, at info because it is expected', function (): void {
    $records = [];

    // A real UUID: this path reaches `deleteBySourceId`, and `source_id` is a
    // uuid column. SQLite accepts any string there, PostgreSQL does not — which
    // is the whole reason this repository runs both engines in CI.
    managerWith(['food' => scriptedProvider(fn (): ?SearchDocument => null)], $records)
        ->reindex('food', (string) Str::orderedUuid());

    expect(array_column($records, 'message'))->toContain(IndexFailure::SourceMissing->value)
        // Unpublishing is normal; it must be visible without being an alarm.
        ->and($records[0]['level'])->toBe('info');
});

it('distinguishes a provider failure from a missing source, and rethrows it', function (): void {
    $records = [];
    $manager = managerWith(
        ['food' => scriptedProvider(function (): ?SearchDocument {
            throw new RuntimeException('catalog unavailable');
        })],
        $records,
    );

    // Rethrown so a queued attempt retries and eventually reaches failed_jobs
    // rather than disappearing.
    expect(fn () => $manager->reindex('food', 'boom'))->toThrow(RuntimeException::class);

    expect(array_column($records, 'message'))
        ->toContain(IndexFailure::ProviderFailed->value)
        ->not->toContain(IndexFailure::SourceMissing->value);
});

it('gives every failure mode its own stable code', function (): void {
    $codes = array_map(fn (IndexFailure $f): string => $f->value, IndexFailure::cases());

    // Distinguishable, as the contract requires — an operator can tell an
    // unknown type from a dead provider from a database problem.
    expect($codes)->toBe(array_unique($codes))
        ->and($codes)->toContain('SEARCH_INDEX_UNKNOWN_TYPE')
        ->and($codes)->toContain('SEARCH_INDEX_SOURCE_MISSING')
        ->and($codes)->toContain('SEARCH_INDEX_PROVIDER_FAILED')
        ->and($codes)->toContain('SEARCH_INDEX_EMBEDDING_FAILED')
        ->and($codes)->toContain('SEARCH_INDEX_PERSIST_FAILED')
        ->and($codes)->toContain('SEARCH_INDEX_JOB_EXHAUSTED');
});

it('never writes document content into a failure log', function (): void {
    $records = [];
    managerWith([], $records)->reindex('not_a_type', 'abc');

    foreach ($records as $record) {
        expect($record['context'])->not->toHaveKey('title')
            ->and($record['context'])->not->toHaveKey('description')
            ->and($record['context'])->not->toHaveKey('search_text');
    }
});

it('reports an unknown type on removal as well as on indexing', function (): void {
    $records = [];
    managerWith([], $records)->remove('not_a_type', 'abc');

    expect(array_column($records, 'message'))->toContain(IndexFailure::UnknownType->value);
});
