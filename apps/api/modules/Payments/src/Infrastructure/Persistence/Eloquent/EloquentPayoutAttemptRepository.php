<?php

declare(strict_types=1);

namespace EruoFood\Payments\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use EruoFood\Payments\Domain\Enum\PaymentProvider;
use EruoFood\Payments\Domain\Enum\PayoutAttemptState;
use EruoFood\Payments\Domain\Exception\PaymentsConflict;
use EruoFood\Payments\Domain\Settlement\PayoutAttempt;
use EruoFood\Payments\Domain\Settlement\PayoutAttemptRepository;
use EruoFood\Payments\Infrastructure\Persistence\Eloquent\Model\PayoutAttemptModel;
use EruoFood\Shared\Domain\Paginated;
use EruoFood\Shared\Domain\ValueObject\Money;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EloquentPayoutAttemptRepository implements PayoutAttemptRepository
{
    public function nextIdentity(): string
    {
        return (string) Str::orderedUuid();
    }

    public function findById(string $id): ?PayoutAttempt
    {
        $model = PayoutAttemptModel::query()->find($id);

        return $model !== null ? $this->toDomain($model) : null;
    }

    public function insert(PayoutAttempt $attempt): void
    {
        try {
            PayoutAttemptModel::query()->insert($this->toRow($attempt));
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                throw new PaymentsConflict(sprintf(
                    'A payout attempt numbered %d already exists for settlement run %s.',
                    $attempt->attemptNo(),
                    $attempt->settlementRunId(),
                ));
            }

            throw $e;
        }
    }

    public function update(PayoutAttempt $attempt): void
    {
        PayoutAttemptModel::query()->where('id', $attempt->id())->update([
            'provider_reference' => $attempt->providerReference(),
            'state' => $attempt->state()->value,
            'failure_reason' => $attempt->failureReason(),
            'raw_response_digest' => $attempt->rawResponseDigest(),
            'submitted_at' => $attempt->submittedAt(),
            'settled_at' => $attempt->settledAt(),
            'updated_at' => $attempt->updatedAt(),
        ]);
    }

    public function forRun(string $settlementRunId): array
    {
        $models = PayoutAttemptModel::query()
            ->where('settlement_run_id', $settlementRunId)
            ->orderBy('attempt_no')
            ->get();

        return array_values(array_map(fn (PayoutAttemptModel $m): PayoutAttempt => $this->toDomain($m), $models->all()));
    }

    public function lastAttemptNo(string $settlementRunId): int
    {
        return (int) PayoutAttemptModel::query()
            ->where('settlement_run_id', $settlementRunId)
            ->max('attempt_no');
    }

    public function needingReconciliation(int $limit): array
    {
        $models = PayoutAttemptModel::query()
            // `created` is included on purpose: a row in that state is one the
            // process wrote and then died before, or during, the transfer. It
            // is the crash case, and it is invisible unless something looks.
            ->whereIn('state', [
                PayoutAttemptState::Created->value,
                PayoutAttemptState::Unknown->value,
                PayoutAttemptState::Reconciling->value,
            ])
            ->orderBy('updated_at')
            ->limit($limit)
            ->get();

        return array_values(array_map(fn (PayoutAttemptModel $m): PayoutAttempt => $this->toDomain($m), $models->all()));
    }

    public function all(int $page, int $perPage): Paginated
    {
        /** @var LengthAwarePaginator<int, PayoutAttemptModel> $paginator */
        $paginator = PayoutAttemptModel::query()->orderByDesc('created_at')->paginate(perPage: $perPage, page: $page);

        return new Paginated(
            array_values(array_map(fn (PayoutAttemptModel $m): PayoutAttempt => $this->toDomain($m), $paginator->items())),
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

        foreach (PayoutAttemptModel::query()->selectRaw('state, COUNT(*) as total')->groupBy('state')->get() as $row) {
            /** @var object{state: string, total: int|string} $row */
            $counts[$row->state] = (int) $row->total;
        }

        return $counts;
    }

    /** @return array<string, mixed> */
    private function toRow(PayoutAttempt $attempt): array
    {
        return [
            'id' => $attempt->id(),
            'settlement_run_id' => $attempt->settlementRunId(),
            'attempt_no' => $attempt->attemptNo(),
            'provider' => $attempt->provider()->value,
            'provider_reference' => $attempt->providerReference(),
            'amount_minor' => $attempt->amount()->minorUnits,
            'currency' => $attempt->amount()->currency,
            'state' => $attempt->state()->value,
            'failure_reason' => $attempt->failureReason(),
            'idempotency_key' => $attempt->idempotencyKey(),
            'correlation_id' => $attempt->correlationId(),
            'raw_response_digest' => $attempt->rawResponseDigest(),
            'created_at' => $attempt->createdAt(),
            'submitted_at' => $attempt->submittedAt(),
            'settled_at' => $attempt->settledAt(),
            'updated_at' => $attempt->updatedAt(),
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

    private function toDomain(PayoutAttemptModel $m): PayoutAttempt
    {
        return PayoutAttempt::reconstitute([
            'id' => $m->id,
            'settlementRunId' => $m->settlement_run_id,
            'attemptNo' => $m->attempt_no,
            'provider' => PaymentProvider::from($m->provider),
            'providerReference' => $m->provider_reference,
            'amount' => new Money($m->amount_minor, $m->currency),
            'state' => PayoutAttemptState::from($m->state),
            'failureReason' => $m->failure_reason,
            'idempotencyKey' => $m->idempotency_key,
            'correlationId' => (string) $m->correlation_id,
            'rawResponseDigest' => $m->raw_response_digest,
            'createdAt' => DateTimeImmutable::createFromInterface($m->created_at),
            'submittedAt' => $m->submitted_at !== null ? DateTimeImmutable::createFromInterface($m->submitted_at) : null,
            'settledAt' => $m->settled_at !== null ? DateTimeImmutable::createFromInterface($m->settled_at) : null,
            'updatedAt' => DateTimeImmutable::createFromInterface($m->updated_at),
        ]);
    }
}
