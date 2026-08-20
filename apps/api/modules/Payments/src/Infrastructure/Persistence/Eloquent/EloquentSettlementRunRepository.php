<?php

declare(strict_types=1);

namespace EruoFood\Payments\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use EruoFood\Payments\Domain\Enum\SettlementRunState;
use EruoFood\Payments\Domain\Exception\PaymentsConflict;
use EruoFood\Payments\Domain\Exception\PaymentsInvalidState;
use EruoFood\Payments\Domain\Settlement\SettlementLine;
use EruoFood\Payments\Domain\Settlement\SettlementRun;
use EruoFood\Payments\Domain\Settlement\SettlementRunRepository;
use EruoFood\Payments\Infrastructure\Persistence\Eloquent\Model\SettlementLineModel;
use EruoFood\Payments\Infrastructure\Persistence\Eloquent\Model\SettlementRunModel;
use EruoFood\Shared\Domain\Exception\ConcurrencyConflict;
use EruoFood\Shared\Domain\Paginated;
use EruoFood\Shared\Domain\ValueObject\Money;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EloquentSettlementRunRepository implements SettlementRunRepository
{
    public function nextIdentity(): string
    {
        return (string) Str::orderedUuid();
    }

    public function findById(string $id): ?SettlementRun
    {
        $model = SettlementRunModel::query()->find($id);

        return $model !== null ? $this->toDomain($model) : null;
    }

    public function findByIdForUpdate(string $id): ?SettlementRun
    {
        $model = SettlementRunModel::query()->lockForUpdate()->find($id);

        return $model !== null ? $this->toDomain($model) : null;
    }

    public function lockMerchant(string $merchantType, string $merchantId): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            // SQLite serialises writers, so there is nothing to serialise here
            // and no advisory-lock primitive to do it with. Stated rather than
            // silently skipped: the fast test path cannot prove this layer, and
            // the concurrency harness is where it is proven.
            return;
        }

        // A transaction-scoped advisory lock keyed on the merchant, released on
        // commit or rollback. There is no merchant row to lock — the payable is
        // derived, not stored — and locking the accrual rows instead would not
        // stop a second worker whose window selects a *different* set of them.
        //
        // Two 32-bit keys rather than one 64-bit hash, so the lock namespace is
        // visibly ours in pg_locks during an incident.
        //
        // The key must be a *signed* int4. `crc32()` returns 0..2^32-1 on a
        // 64-bit build, so a little over half of all merchant ids produce a
        // value PostgreSQL rejects outright with "out of range for type
        // integer" — which would have taken the settlement lock out for those
        // merchants and left it working for everybody else. The concurrency
        // harness caught it; no unit test would have, because the failure
        // depends on which uuid you happen to hash.
        $key = crc32($merchantType.':'.$merchantId);
        if ($key > 0x7FFFFFFF) {
            $key -= 0x100000000;
        }

        DB::statement('SELECT pg_advisory_xact_lock(?, ?)', [0x5E77, $key]);
    }

    public function insert(SettlementRun $run, array $lines): void
    {
        try {
            DB::transaction(function () use ($run, $lines): void {
                SettlementRunModel::query()->insert($this->toRow($run));

                if ($lines === []) {
                    return;
                }

                SettlementLineModel::query()->insert(array_map(
                    static fn (SettlementLine $line): array => [
                        'id' => $line->id,
                        'settlement_run_id' => $line->settlementRunId,
                        'accrual_id' => $line->accrualId,
                        'currency' => $line->net->currency,
                        'net_minor' => $line->net->minorUnits,
                        'created_at' => $line->createdAt,
                    ],
                    $lines,
                ));
            });
        } catch (QueryException $e) {
            if (! $this->isUniqueViolation($e)) {
                throw $e;
            }

            // Which index fired matters to the caller: a window collision means
            // "somebody is already settling this period", while an accrual
            // collision means "one of these orders is already being paid for".
            // Both are conflicts; only one of them is about this run.
            throw new PaymentsConflict(
                str_contains($e->getMessage(), 'accrual')
                    ? 'One or more of these accruals is already on another settlement run.'
                    : 'A live settlement run already covers this merchant and window.',
            );
        }
    }

    public function update(SettlementRun $run, int $expectedVersion): void
    {
        $affected = SettlementRunModel::query()
            ->where('id', $run->id())
            ->where('version', $expectedVersion)
            ->update([
                'state' => $run->state()->value,
                'approved_by' => $run->approvedBy(),
                'approved_at' => $run->approvedAt(),
                'executed_by' => $run->executedBy(),
                'executed_at' => $run->executedAt(),
                'failure_reason' => $run->failureReason(),
                'version' => $run->version(),
                'updated_at' => $run->updatedAt(),
            ]);

        if ($affected === 0) {
            // Either the row moved on under us, or it is gone. Both are the
            // caller holding something stale, and neither is safe to write over.
            throw ConcurrencyConflict::on('settlement run', $run->id());
        }
    }

    public function releaseLines(SettlementRun $run): void
    {
        if (! $run->state()->releasesAccruals()) {
            // Deleting the lines of a live run would hand its accruals to
            // another run while this one is still trying to pay them.
            throw new PaymentsInvalidState(sprintf(
                'A settlement run in state "%s" does not release its accruals.',
                $run->state()->value,
            ));
        }

        SettlementLineModel::query()->where('settlement_run_id', $run->id())->delete();
    }

    public function linesFor(string $runId): array
    {
        $models = SettlementLineModel::query()
            ->where('settlement_run_id', $runId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        return array_values(array_map(
            static fn (SettlementLineModel $m): SettlementLine => SettlementLine::reconstitute(
                $m->id,
                $m->settlement_run_id,
                $m->accrual_id,
                new Money($m->net_minor, $m->currency),
                DateTimeImmutable::createFromInterface($m->created_at),
            ),
            $models->all(),
        ));
    }

    public function all(?SettlementRunState $state, int $page, int $perPage): Paginated
    {
        $query = SettlementRunModel::query()->orderByDesc('created_at');
        if ($state !== null) {
            $query->where('state', $state->value);
        }

        /** @var LengthAwarePaginator<int, SettlementRunModel> $paginator */
        $paginator = $query->paginate(perPage: $perPage, page: $page);

        return $this->paginate($paginator, $page, $perPage);
    }

    public function forMerchant(string $merchantType, string $merchantId, int $page, int $perPage): Paginated
    {
        /** @var LengthAwarePaginator<int, SettlementRunModel> $paginator */
        $paginator = SettlementRunModel::query()
            ->where('merchant_type', $merchantType)
            ->where('merchant_id', $merchantId)
            ->orderByDesc('created_at')
            ->paginate(perPage: $perPage, page: $page);

        return $this->paginate($paginator, $page, $perPage);
    }

    public function awaitingReconciliation(int $limit): array
    {
        $models = SettlementRunModel::query()
            ->whereIn('state', [SettlementRunState::Unknown->value, SettlementRunState::Reconciling->value])
            ->orderBy('updated_at')
            ->limit($limit)
            ->get();

        return array_values(array_map(fn (SettlementRunModel $m): SettlementRun => $this->toDomain($m), $models->all()));
    }

    public function liveRunForWindow(
        string $merchantType,
        string $merchantId,
        string $currency,
        DateTimeImmutable $windowStart,
        DateTimeImmutable $windowEnd,
    ): ?SettlementRun {
        $model = SettlementRunModel::query()
            ->where('merchant_type', $merchantType)
            ->where('merchant_id', $merchantId)
            ->where('currency', $currency)
            ->where('window_start', $windowStart)
            ->where('window_end', $windowEnd)
            ->whereNotIn('state', $this->releasedStates())
            ->first();

        return $model !== null ? $this->toDomain($model) : null;
    }

    public function countsByState(): array
    {
        // `->pluck('total', 'state')` would be shorter, and PHPStan is right
        // to refuse it: `total` is a raw aggregate alias, not a column on the
        // model, so the shape is unverifiable. Reading the rows and building
        // the map keeps the types honest.
        $counts = [];

        foreach (SettlementRunModel::query()->selectRaw('state, COUNT(*) as total')->groupBy('state')->get() as $row) {
            /** @var object{state: string, total: int|string} $row */
            $counts[$row->state] = (int) $row->total;
        }

        return $counts;
    }

    public function paidOutNetMinor(): int
    {
        return (int) DB::table('payments_settlement_lines as l')
            ->join('payments_settlement_runs as r', 'r.id', '=', 'l.settlement_run_id')
            // Succeeded only — the states in which the MerchantPayable → Payouts
            // posting has actually been committed. See the port's docblock.
            ->where('r.state', SettlementRunState::Succeeded->value)
            ->sum('l.net_minor');
    }

    /**
     * States whose lines no longer hold their accruals.
     *
     * One list, used by every query that has to distinguish "committed" from
     * "abandoned". Duplicating it was how the payable and the run selection
     * would eventually disagree about what counted.
     *
     * @return list<string>
     */
    private function releasedStates(): array
    {
        return array_values(array_map(
            static fn (SettlementRunState $s): string => $s->value,
            array_filter(
                SettlementRunState::cases(),
                static fn (SettlementRunState $s): bool => $s->releasesAccruals(),
            ),
        ));
    }

    /** @return array<string, mixed> */
    private function toRow(SettlementRun $run): array
    {
        return [
            'id' => $run->id(),
            'merchant_type' => $run->merchantType(),
            'merchant_id' => $run->merchantId(),
            'currency' => $run->currency(),
            'window_start' => $run->windowStart(),
            'window_end' => $run->windowEnd(),
            'gross_minor' => $run->gross()->minorUnits,
            'commission_minor' => $run->commission()->minorUnits,
            'fee_minor' => $run->fee()->minorUnits,
            'net_minor' => $run->net()->minorUnits,
            'state' => $run->state()->value,
            'idempotency_key' => $run->idempotencyKey(),
            'settlement_reference' => $run->settlementReference(),
            'correlation_id' => $run->correlationId(),
            'computed_by' => $run->computedBy(),
            'computed_at' => $run->computedAt(),
            'approved_by' => $run->approvedBy(),
            'approved_at' => $run->approvedAt(),
            'executed_by' => $run->executedBy(),
            'executed_at' => $run->executedAt(),
            'failure_reason' => $run->failureReason(),
            'version' => $run->version(),
            'created_at' => $run->createdAt(),
            'updated_at' => $run->updatedAt(),
        ];
    }

    /**
     * @param LengthAwarePaginator<int, SettlementRunModel> $paginator
     * @return Paginated<SettlementRun>
     */
    private function paginate(LengthAwarePaginator $paginator, int $page, int $perPage): Paginated
    {
        return new Paginated(
            array_values(array_map(fn (SettlementRunModel $m): SettlementRun => $this->toDomain($m), $paginator->items())),
            $paginator->total(),
            $page,
            $perPage,
        );
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

    private function toDomain(SettlementRunModel $m): SettlementRun
    {
        $currency = $m->currency;

        return SettlementRun::reconstitute([
            'id' => $m->id,
            'merchantType' => $m->merchant_type,
            'merchantId' => $m->merchant_id,
            'currency' => $currency,
            'windowStart' => DateTimeImmutable::createFromInterface($m->window_start),
            'windowEnd' => DateTimeImmutable::createFromInterface($m->window_end),
            'gross' => new Money($m->gross_minor, $currency),
            'commission' => new Money($m->commission_minor, $currency),
            'fee' => new Money($m->fee_minor, $currency),
            'net' => new Money($m->net_minor, $currency),
            'state' => SettlementRunState::from($m->state),
            'idempotencyKey' => $m->idempotency_key,
            'settlementReference' => $m->settlement_reference,
            'correlationId' => (string) $m->correlation_id,
            'computedBy' => $m->computed_by,
            'computedAt' => DateTimeImmutable::createFromInterface($m->computed_at ?? $m->created_at),
            'approvedBy' => $m->approved_by,
            'approvedAt' => $m->approved_at !== null ? DateTimeImmutable::createFromInterface($m->approved_at) : null,
            'executedBy' => $m->executed_by,
            'executedAt' => $m->executed_at !== null ? DateTimeImmutable::createFromInterface($m->executed_at) : null,
            'failureReason' => $m->failure_reason,
            'version' => $m->version,
            'createdAt' => DateTimeImmutable::createFromInterface($m->created_at),
            'updatedAt' => DateTimeImmutable::createFromInterface($m->updated_at),
        ]);
    }
}
