<?php

declare(strict_types=1);

namespace EruoFood\Payments\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use EruoFood\Payments\Domain\Enum\DiscrepancyKind;
use EruoFood\Payments\Domain\Enum\ReconciliationState;
use EruoFood\Payments\Domain\Settlement\ReconciliationCase;
use EruoFood\Payments\Domain\Settlement\ReconciliationCaseRepository;
use EruoFood\Payments\Infrastructure\Persistence\Eloquent\Model\ReconciliationCaseModel;
use EruoFood\Shared\Domain\Exception\ConcurrencyConflict;
use EruoFood\Shared\Domain\Paginated;
use EruoFood\Shared\Domain\ValueObject\Money;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EloquentReconciliationCaseRepository implements ReconciliationCaseRepository
{
    public function nextIdentity(): string
    {
        return (string) Str::orderedUuid();
    }

    public function findById(string $id): ?ReconciliationCase
    {
        $model = ReconciliationCaseModel::query()->find($id);

        return $model !== null ? $this->toDomain($model) : null;
    }

    public function findByIdForUpdate(string $id): ?ReconciliationCase
    {
        $model = ReconciliationCaseModel::query()->lockForUpdate()->find($id);

        return $model !== null ? $this->toDomain($model) : null;
    }

    public function openOrReturnExisting(ReconciliationCase $case): ReconciliationCase
    {
        try {
            // The insert runs inside its own nested transaction so that, when
            // the partial unique index refuses it, only a savepoint is rolled
            // back.
            //
            // Without this the recovery query below is unreachable on
            // PostgreSQL: a statement that fails inside a transaction aborts
            // the whole transaction, and every subsequent statement returns
            // "current transaction is aborted" until it ends. SQLite has no
            // such rule, so the fast test suite passed and the production
            // engine did not — the reconciler would have crashed the caller
            // every time it met a discrepancy it had already recorded, which is
            // to say every time after the first.
            DB::transaction(function () use ($case): void {
                ReconciliationCaseModel::query()->insert($this->toRow($case));
            });

            return $case;
        } catch (QueryException $e) {
            if (! $this->isUniqueViolation($e)) {
                throw $e;
            }

            // The partial index refused a second unresolved case for the same
            // subject. Returning the existing one rather than throwing is what
            // lets a reconciler run on a short schedule against a long-lived
            // problem without flooding the queue.
            $existing = $this->findOpenFor($case->kind(), $case->subjectType(), $case->subjectId());

            return $existing ?? throw $e;
        }
    }

    public function update(ReconciliationCase $case, int $expectedVersion): void
    {
        $affected = ReconciliationCaseModel::query()
            ->where('id', $case->id())
            ->where('version', $expectedVersion)
            ->update([
                'state' => $case->state()->value,
                'resolved_at' => $case->resolvedAt(),
                'resolved_by' => $case->resolvedBy(),
                'resolution_note' => $case->resolutionNote(),
                'compensating_posting_id' => $case->compensatingPostingId(),
                'version' => $case->version(),
                'updated_at' => $case->updatedAt(),
            ]);

        if ($affected === 0) {
            throw ConcurrencyConflict::on('reconciliation case', $case->id());
        }
    }

    public function findOpenFor(DiscrepancyKind $kind, string $subjectType, string $subjectId): ?ReconciliationCase
    {
        $query = ReconciliationCaseModel::query()
            ->where('kind', $kind->value)
            ->where('subject_type', $subjectType)
            ->whereNotIn('state', [
                ReconciliationState::ResolvedMatched->value,
                ReconciliationState::ResolvedAdjusted->value,
            ]);

        $model = $query->where('subject_id', $subjectId)->first();

        return $model !== null ? $this->toDomain($model) : null;
    }

    public function all(?ReconciliationState $state, int $page, int $perPage): Paginated
    {
        $query = ReconciliationCaseModel::query()->orderByDesc('opened_at');
        if ($state !== null) {
            $query->where('state', $state->value);
        }

        /** @var LengthAwarePaginator<int, ReconciliationCaseModel> $paginator */
        $paginator = $query->paginate(perPage: $perPage, page: $page);

        return new Paginated(
            array_values(array_map(fn (ReconciliationCaseModel $m): ReconciliationCase => $this->toDomain($m), $paginator->items())),
            $paginator->total(),
            $page,
            $perPage,
        );
    }

    public function countsByState(): array
    {
        // `->pluck('total', 'state')` would be shorter, and PHPStan is right
        // to refuse it: `total` is a raw aggregate alias, not a column on the
        // model, so the shape is unverifiable. Reading the rows and building
        // the map keeps the types honest.
        $counts = [];

        foreach (ReconciliationCaseModel::query()->selectRaw('state, COUNT(*) as total')->groupBy('state')->get() as $row) {
            /** @var object{state: string, total: int|string} $row */
            $counts[$row->state] = (int) $row->total;
        }

        return $counts;
    }

    public function unresolvedCount(): int
    {
        return (int) ReconciliationCaseModel::query()
            ->whereNotIn('state', [
                ReconciliationState::ResolvedMatched->value,
                ReconciliationState::ResolvedAdjusted->value,
            ])
            ->count();
    }

    /** @return array<string, mixed> */
    private function toRow(ReconciliationCase $case): array
    {
        return [
            'id' => $case->id(),
            'kind' => $case->kind()->value,
            'subject_type' => $case->subjectType(),
            'subject_id' => $case->subjectId(),
            'expected_minor' => $case->expected()->minorUnits,
            'observed_minor' => $case->observed()->minorUnits,
            'currency' => $case->expected()->currency,
            'state' => $case->state()->value,
            'detail' => $case->detail(),
            'opened_at' => $case->openedAt(),
            'resolved_at' => $case->resolvedAt(),
            'resolved_by' => $case->resolvedBy(),
            'resolution_note' => $case->resolutionNote(),
            'compensating_posting_id' => $case->compensatingPostingId(),
            'correlation_id' => $case->correlationId(),
            'version' => $case->version(),
            'created_at' => $case->openedAt(),
            'updated_at' => $case->updatedAt(),
        ];
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? '');

        if ($sqlState === '23505') {
            return true;
        }

        if ($sqlState !== '23000') {
            return false;
        }

        return DB::connection()->getDriverName() !== 'sqlite'
            || str_contains(strtolower($e->getMessage()), 'unique constraint failed');
    }

    private function toDomain(ReconciliationCaseModel $m): ReconciliationCase
    {
        $currency = $m->currency;

        return ReconciliationCase::reconstitute([
            'id' => $m->id,
            'kind' => DiscrepancyKind::from($m->kind),
            'subjectType' => $m->subject_type,
            'subjectId' => $m->subject_id,
            'expected' => new Money($m->expected_minor, $currency),
            'observed' => new Money($m->observed_minor, $currency),
            'state' => ReconciliationState::from($m->state),
            'detail' => $m->detail,
            'openedAt' => DateTimeImmutable::createFromInterface($m->opened_at),
            'resolvedAt' => $m->resolved_at !== null ? DateTimeImmutable::createFromInterface($m->resolved_at) : null,
            'resolvedBy' => $m->resolved_by,
            'resolutionNote' => $m->resolution_note,
            'compensatingPostingId' => $m->compensating_posting_id,
            'correlationId' => (string) $m->correlation_id,
            'version' => $m->version,
            'updatedAt' => DateTimeImmutable::createFromInterface($m->updated_at),
        ]);
    }
}
