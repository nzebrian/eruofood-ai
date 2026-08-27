<?php

declare(strict_types=1);

use EruoFood\Search\Domain\Capability\CapabilityState;
use EruoFood\Search\Domain\Capability\SearchCapability;
use EruoFood\Search\Infrastructure\Capability\SearchCapabilityProbe;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * M38-DB-001 and M38-VECTOR-001 — capability is measured, never assumed.
 *
 * The original migration created pgvector and pg_trgm inside
 * `try { … } catch (\Throwable) { }` and continued as though both existed. A
 * missing extension, a permissions error and a healthy install were
 * indistinguishable afterwards. No Postgres image in this repository shipped
 * pgvector, so the most likely real state was "silently absent everywhere",
 * while `pgvectorEnabled()` answered a `hasColumn` question that does not imply
 * a usable index.
 */

/** A connection stub that answers the probe's two queries however we like. */
function fakeConnection(string $driver, callable $select): ConnectionInterface
{
    return new class ($driver, $select) implements ConnectionInterface {
        public function __construct(private string $driver, private $selectHandler)
        {
        }

        public function getDriverName(): string
        {
            return $this->driver;
        }

        public function select($query, $bindings = [], $useReadPdo = true)
        {
            return ($this->selectHandler)($query, $bindings);
        }

        // The probe uses only getDriverName() and select(); the rest of the
        // interface is inert here.
        public function table($table, $as = null)
        {
            throw new BadMethodCallException('not used');
        }

        public function raw($value)
        {
            throw new BadMethodCallException('not used');
        }

        public function selectOne($query, $bindings = [], $useReadPdo = true)
        {
            return null;
        }

        public function cursor($query, $bindings = [], $useReadPdo = true)
        {
            return [];
        }

        public function insert($query, $bindings = [])
        {
            return true;
        }

        public function update($query, $bindings = [])
        {
            return 0;
        }

        public function delete($query, $bindings = [])
        {
            return 0;
        }

        public function statement($query, $bindings = [])
        {
            return true;
        }

        public function affectingStatement($query, $bindings = [])
        {
            return 0;
        }

        public function unprepared($query)
        {
            return true;
        }

        public function prepareBindings(array $bindings)
        {
            return $bindings;
        }

        public function transaction(Closure $callback, $attempts = 1)
        {
            return $callback($this);
        }

        public function beginTransaction()
        {
        }

        public function commit()
        {
        }

        public function rollBack($toLevel = null)
        {
        }

        public function transactionLevel()
        {
            return 0;
        }

        public function pretend(Closure $callback)
        {
            return [];
        }

        public function getDatabaseName()
        {
            return 'test';
        }

        public function scalar($query, $bindings = [], $useReadPdo = true)
        {
            return null;
        }
    };
}

it('reports every capability as available when the database has them', function (): void {
    $probe = new SearchCapabilityProbe(
        fakeConnection('pgsql', fn (): array => [(object) ['?column?' => 1]]),
        driver: 'pgsql',
        vectorRequested: true,
        trigramRequested: true,
    );

    $capability = $probe->probe();

    expect($capability->vector)->toBe(CapabilityState::Available)
        ->and($capability->vectorIndex)->toBe(CapabilityState::Available)
        ->and($capability->trigram)->toBe(CapabilityState::Available)
        ->and($capability->nativeVectorSearchActive())->toBeTrue()
        ->and($capability->trigramAccelerationActive())->toBeTrue()
        ->and($capability->isDegraded())->toBeFalse();
});

it('reports unavailable — not healthy — when the extension is absent', function (): void {
    $probe = new SearchCapabilityProbe(
        fakeConnection('pgsql', fn (): array => []),
        driver: 'pgsql',
        vectorRequested: true,
        trigramRequested: true,
    );

    $capability = $probe->probe();

    expect($capability->vector)->toBe(CapabilityState::Unavailable)
        ->and($capability->nativeVectorSearchActive())->toBeFalse()
        ->and($capability->toArray()['native_vector_search'])->toBe('fallback')
        ->and($capability->isDegraded())->toBeTrue();
});

it('distinguishes a failed probe from a confirmed absence', function (): void {
    $probe = new SearchCapabilityProbe(
        fakeConnection('pgsql', function (): array {
            throw new RuntimeException('connection reset');
        }),
        driver: 'pgsql',
        vectorRequested: true,
        trigramRequested: true,
    );

    $capability = $probe->probe();

    // "We could not find out" is not the same claim as "it is not there", and
    // rounding the first down to the second is how a broken probe starts
    // reporting healthy.
    expect($capability->vector)->toBe(CapabilityState::ProbeFailed)
        ->and($capability->vector)->not->toBe(CapabilityState::Unavailable)
        ->and($capability->nativeVectorSearchActive())->toBeFalse()
        ->and($capability->isDegraded())->toBeTrue();
});

it('reports configuration-disabled distinctly from broken', function (): void {
    $probe = new SearchCapabilityProbe(
        fakeConnection('pgsql', fn (): array => []),
        driver: 'pgsql',
        vectorRequested: false,
        trigramRequested: false,
    );

    $capability = $probe->probe();

    expect($capability->vector)->toBe(CapabilityState::DisabledByConfig)
        ->and($capability->isDegraded())->toBeFalse()
        ->and($capability->nativeVectorSearchActive())->toBeFalse();
});

it('never claims the index is active when the extension is present but the index is not', function (): void {
    // Extension yes, index no — the exact state an interrupted migration leaves.
    $probe = new SearchCapabilityProbe(
        fakeConnection('pgsql', fn (string $q): array => str_contains($q, 'pg_extension') ? [(object) ['x' => 1]] : []),
        driver: 'pgsql',
        vectorRequested: true,
        trigramRequested: true,
    );

    $capability = $probe->probe();

    expect($capability->vector)->toBe(CapabilityState::Available)
        ->and($capability->vectorIndex)->toBe(CapabilityState::Unavailable)
        // The whole point: an extension without its index is not an
        // accelerated query path, and must not be advertised as one.
        ->and($capability->nativeVectorSearchActive())->toBeFalse();
});

it('reports the portable path honestly on a non-postgres driver', function (): void {
    $probe = new SearchCapabilityProbe(
        fakeConnection('sqlite', fn (): array => []),
        driver: 'sqlite',
        vectorRequested: true,
        trigramRequested: true,
    );

    $capability = $probe->probe();

    expect($capability->driver)->toBe('sqlite')
        ->and($capability->nativeVectorSearchActive())->toBeFalse()
        ->and($capability->detail)->toContain('portable');
});

it('exposes the live capability to administrators and nobody else', function (): void {
    // Anonymous callers get no view of the backend's internals.
    expect($this->getJson('/api/v1/search/admin/capability')->status())
        ->toBeGreaterThanOrEqual(400);
});

it('resolves a real capability snapshot from the container', function (): void {
    $capability = app(SearchCapability::class);

    expect($capability)->toBeInstanceOf(SearchCapability::class)
        ->and($capability->toArray())->toHaveKeys([
            'driver', 'vector_extension', 'vector_index', 'native_vector_search',
            'trigram_extension', 'trigram_index', 'trigram_acceleration', 'degraded',
        ]);
});
