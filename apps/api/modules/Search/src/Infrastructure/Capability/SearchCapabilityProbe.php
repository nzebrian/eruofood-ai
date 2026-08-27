<?php

declare(strict_types=1);

namespace EruoFood\Search\Infrastructure\Capability;

use EruoFood\Search\Domain\Capability\CapabilityState;
use EruoFood\Search\Domain\Capability\SearchCapability;
use Illuminate\Database\ConnectionInterface;
use Throwable;

/**
 * Asks the database what it can actually do (M38-DB-001, M38-VECTOR-001).
 *
 * The migration that created `embedding_vec` and the trigram index wrapped both
 * `CREATE EXTENSION` calls in `try { … } catch (\Throwable) { }`. If the
 * extension was missing the acceleration silently did not exist, the code went
 * on using `pgvectorEnabled()` (a `hasColumn` check that answers a different
 * question) and nothing anywhere reported the difference. No Postgres image in
 * this repository shipped pgvector, so the realistic state was "dormant
 * everywhere, silently".
 *
 * This asks `pg_extension` and `pg_indexes` directly and returns what it found,
 * including "I could not find out" as a distinct answer. It never converts a
 * failure into a healthy state.
 */
final readonly class SearchCapabilityProbe
{
    /**
     * The driver name is passed in rather than read off the connection:
     * `getDriverName()` lives on the concrete `Connection`, not on
     * `ConnectionInterface`, and reaching for it through an inline `@var`
     * override would be asserting a type PHPStan cannot see — the same
     * "trust me" pattern this milestone is removing elsewhere.
     */
    public function __construct(
        private ConnectionInterface $connection,
        private string $driver,
        private bool $vectorRequested,
        private bool $trigramRequested,
    ) {
    }

    public function probe(): SearchCapability
    {
        $driver = $this->driver;

        // Only PostgreSQL offers these. On any other driver the honest answer
        // is "not available here", not "broken".
        if ($driver !== 'pgsql') {
            return new SearchCapability(
                driver: $driver,
                vector: CapabilityState::Unavailable,
                vectorIndex: CapabilityState::Unavailable,
                trigram: CapabilityState::Unavailable,
                trigramIndex: CapabilityState::Unavailable,
                detail: "driver '{$driver}' has no pgvector/pg_trgm; the portable PHP path is in use",
            );
        }

        $vector = $this->vectorRequested
            ? $this->extensionState('vector')
            : CapabilityState::DisabledByConfig;

        $trigram = $this->trigramRequested
            ? $this->extensionState('pg_trgm')
            : CapabilityState::DisabledByConfig;

        return new SearchCapability(
            driver: $driver,
            vector: $vector,
            // An extension without its index is not an accelerated query path,
            // so the index is probed as a capability in its own right.
            vectorIndex: $vector->isUsable()
                ? $this->indexState('search_documents_embedding_vec_idx')
                : $this->inherit($vector),
            trigram: $trigram,
            trigramIndex: $trigram->isUsable()
                ? $this->indexState('search_documents_search_text_trgm_idx')
                : $this->inherit($trigram),
        );
    }

    private function extensionState(string $name): CapabilityState
    {
        try {
            $rows = $this->connection->select(
                'SELECT 1 FROM pg_extension WHERE extname = ? LIMIT 1',
                [$name],
            );
        } catch (Throwable $e) {
            // The query itself failed. That is not evidence of absence.
            return CapabilityState::ProbeFailed;
        }

        return $rows === [] ? CapabilityState::Unavailable : CapabilityState::Available;
    }

    private function indexState(string $indexName): CapabilityState
    {
        try {
            $rows = $this->connection->select(
                'SELECT 1 FROM pg_indexes WHERE indexname = ? LIMIT 1',
                [$indexName],
            );
        } catch (Throwable) {
            return CapabilityState::ProbeFailed;
        }

        return $rows === [] ? CapabilityState::Unavailable : CapabilityState::Available;
    }

    /**
     * An index cannot be usable when its extension is not. Carry the reason
     * through rather than reporting a second, unrelated-looking failure.
     */
    private function inherit(CapabilityState $parent): CapabilityState
    {
        return match ($parent) {
            CapabilityState::DisabledByConfig => CapabilityState::DisabledByConfig,
            CapabilityState::ProbeFailed => CapabilityState::ProbeFailed,
            default => CapabilityState::Unavailable,
        };
    }
}
