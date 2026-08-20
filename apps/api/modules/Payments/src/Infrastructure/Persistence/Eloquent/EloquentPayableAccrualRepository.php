<?php

declare(strict_types=1);

namespace EruoFood\Payments\Infrastructure\Persistence\Eloquent;

use Closure;
use DateTimeImmutable;
use EruoFood\Payments\Domain\Enum\AccrualType;
use EruoFood\Payments\Domain\Enum\PaymentStatus;
use EruoFood\Payments\Domain\Exception\PaymentsConflict;
use EruoFood\Payments\Domain\Settlement\PayableAccrual;
use EruoFood\Payments\Domain\Settlement\PayableAccrualRepository;
use EruoFood\Payments\Infrastructure\Persistence\Eloquent\Model\PayableAccrualModel;
use EruoFood\Shared\Domain\Paginated;
use EruoFood\Shared\Domain\ValueObject\Money;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EloquentPayableAccrualRepository implements PayableAccrualRepository
{
    public function nextIdentity(): string
    {
        return (string) Str::orderedUuid();
    }

    public function findById(string $id): ?PayableAccrual
    {
        $model = PayableAccrualModel::query()->find($id);

        return $model !== null ? $this->toDomain($model) : null;
    }

    public function findEarningForOrder(string $orderId): ?PayableAccrual
    {
        $model = PayableAccrualModel::query()
            ->where('order_id', $orderId)
            ->where('type', AccrualType::Earning->value)
            ->first();

        return $model !== null ? $this->toDomain($model) : null;
    }

    public function insert(PayableAccrual $accrual): void
    {
        try {
            // A raw insert rather than a model save, so that a duplicate
            // surfaces as a constraint violation instead of Eloquent helpfully
            // turning it into an update — which would silently rewrite an
            // append-only financial record.
            PayableAccrualModel::query()->insert([
                'id' => $accrual->id(),
                'type' => $accrual->type()->value,
                'merchant_type' => $accrual->merchantType(),
                'merchant_id' => $accrual->merchantId(),
                'order_id' => $accrual->orderId(),
                'payment_id' => $accrual->paymentId(),
                'refund_id' => $accrual->refundId(),
                'currency' => $accrual->net()->currency,
                'gross_minor' => $accrual->gross()->minorUnits,
                'commission_minor' => $accrual->commission()->minorUnits,
                'fee_minor' => $accrual->fee()->minorUnits,
                'net_minor' => $accrual->net()->minorUnits,
                'commission_rate_bps' => $accrual->commissionRateBps(),
                'ledger_posted' => $accrual->ledgerPosted(),
                'correlation_id' => $accrual->correlationId(),
                'accrued_at' => $accrual->accruedAt(),
                'created_at' => $accrual->accruedAt(),
            ]);
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                throw new PaymentsConflict($accrual->refundId() !== null
                    ? "A payable adjustment already exists for refund {$accrual->refundId()}."
                    : "An accrual already exists for order {$accrual->orderId()}.");
            }

            throw $e;
        }
    }

    public function forMerchant(string $merchantType, string $merchantId, int $page, int $perPage): Paginated
    {
        /** @var LengthAwarePaginator<int, PayableAccrualModel> $paginator */
        $paginator = PayableAccrualModel::query()
            ->where('merchant_type', $merchantType)
            ->where('merchant_id', $merchantId)
            ->orderByDesc('accrued_at')
            ->paginate(perPage: $perPage, page: $page);

        return new Paginated(
            array_values(array_map(fn (PayableAccrualModel $m): PayableAccrual => $this->toDomain($m), $paginator->items())),
            $paginator->total(),
            $page,
            $perPage,
        );
    }

    public function unsettledEarnings(
        string $merchantType,
        string $merchantId,
        string $currency,
        DateTimeImmutable $windowStart,
        DateTimeImmutable $windowEnd,
    ): array {
        $models = PayableAccrualModel::query()
            ->where('merchant_type', $merchantType)
            ->where('merchant_id', $merchantId)
            ->where('currency', $currency)
            ->where('type', AccrualType::Earning->value)
            // Report-only accruals are excluded here as well as in the domain.
            // If one ever reached a settlement line, the payout would move money
            // the ledger still calls escrow.
            ->where('ledger_posted', true)
            ->whereBetween('accrued_at', [$windowStart, $windowEnd])
            ->whereNotExists($this->settledLineExists())
            ->orderBy('accrued_at')
            ->orderBy('id')
            ->get();

        return array_values(array_map(fn (PayableAccrualModel $m): PayableAccrual => $this->toDomain($m), $models->all()));
    }

    public function derivedPayableMinor(string $merchantType, string $merchantId, string $currency): int
    {
        $accrued = (int) PayableAccrualModel::query()
            ->where('merchant_type', $merchantType)
            ->where('merchant_id', $merchantId)
            ->where('currency', $currency)
            ->where('ledger_posted', true)
            ->sum('net_minor');

        // Everything already committed to a settlement run that has not been
        // abandoned. A cancelled or failed run releases its accruals, so its
        // lines must not count against the payable.
        $settled = (int) DB::table('payments_settlement_lines as l')
            ->join('payments_settlement_runs as r', 'r.id', '=', 'l.settlement_run_id')
            ->where('r.merchant_type', $merchantType)
            ->where('r.merchant_id', $merchantId)
            ->where('r.currency', $currency)
            ->whereNotIn('r.state', ['cancelled', 'failed', 'reversed'])
            ->sum('l.net_minor');

        return $accrued - $settled;
    }

    public function postedNetMinor(): int
    {
        return (int) PayableAccrualModel::query()->where('ledger_posted', true)->sum('net_minor');
    }

    public function orphanEarnings(int $limit): array
    {
        $rows = PayableAccrualModel::query()
            ->select(['id', 'payment_id', 'net_minor'])
            ->where('type', AccrualType::Earning->value)
            ->where('ledger_posted', true)
            ->whereNotExists(static function (QueryBuilder $query): void {
                $query->select(DB::raw(1))
                    ->from('payments_payments')
                    ->whereColumn('payments_payments.id', 'payments_payable_accruals.payment_id')
                    ->where('payments_payments.status', PaymentStatus::Succeeded->value);
            })
            ->orderBy('accrued_at')
            ->limit($limit)
            ->get();

        return array_values(array_map(
            static fn (PayableAccrualModel $m): array => [
                'accrual_id' => $m->id,
                'payment_id' => $m->payment_id,
                'net_minor' => $m->net_minor,
            ],
            $rows->all(),
        ));
    }

    public function totals(): array
    {
        /** @var object{count: int|null, gross: int|null, commission: int|null, fee: int|null, net: int|null}|null $row */
        $row = PayableAccrualModel::query()
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(gross_minor), 0) as gross, COALESCE(SUM(commission_minor), 0) as commission, COALESCE(SUM(fee_minor), 0) as fee, COALESCE(SUM(net_minor), 0) as net')
            ->first();

        return [
            'count' => (int) ($row->count ?? 0),
            'earnings' => (int) PayableAccrualModel::query()->where('type', AccrualType::Earning->value)->count(),
            'adjustments' => (int) PayableAccrualModel::query()->where('type', AccrualType::RefundAdjustment->value)->count(),
            'gross_minor' => (int) ($row->gross ?? 0),
            'commission_minor' => (int) ($row->commission ?? 0),
            'fee_minor' => (int) ($row->fee ?? 0),
            'net_minor' => (int) ($row->net ?? 0),
            'reporting_only' => (int) PayableAccrualModel::query()->where('ledger_posted', false)->count(),
        ];
    }

    public function merchantsWithPayable(int $limit): array
    {
        $rows = PayableAccrualModel::query()
            ->selectRaw('merchant_type, merchant_id, currency, COUNT(*) as accruals')
            ->where('ledger_posted', true)
            ->groupBy('merchant_type', 'merchant_id', 'currency')
            ->orderBy('merchant_type')
            ->orderBy('merchant_id')
            ->limit($limit)
            ->get();

        $out = [];
        foreach ($rows as $row) {
            /** @var object{merchant_type: string, merchant_id: string, currency: string, accruals: int} $row */
            $payable = $this->derivedPayableMinor($row->merchant_type, $row->merchant_id, $row->currency);
            if ($payable === 0) {
                continue;
            }

            $out[] = [
                'merchant_type' => $row->merchant_type,
                'merchant_id' => $row->merchant_id,
                'currency' => $row->currency,
                'payable_minor' => $payable,
                'accruals' => (int) $row->accruals,
            ];
        }

        return $out;
    }

    /**
     * "This accrual is already on a settlement line that still counts."
     *
     * Extracted because the same correlated subquery decides both what a new
     * run may pick up and what the payable subtracts; two copies would drift.
     */
    private function settledLineExists(): Closure
    {
        return static function (QueryBuilder $query): void {
            $query->select(DB::raw(1))
                ->from('payments_settlement_lines as l')
                ->join('payments_settlement_runs as r', 'r.id', '=', 'l.settlement_run_id')
                ->whereColumn('l.accrual_id', 'payments_payable_accruals.id')
                ->whereNotIn('r.state', ['cancelled', 'failed', 'reversed']);
        };
    }

    /**
     * Whether a query failure was a unique-constraint violation.
     *
     * Matched on SQLSTATE rather than on the driver message where possible: the
     * message differs between SQLite and PostgreSQL. Deliberately narrow — a
     * CHECK failure must not be mistaken for a duplicate and swallowed as
     * "already recorded".
     */
    private function isUniqueViolation(QueryException $e): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? '');

        if ($sqlState === '23505') {
            return true; // PostgreSQL unique_violation
        }

        if ($sqlState !== '23000') {
            return false;
        }

        return DB::connection()->getDriverName() !== 'sqlite'
            || str_contains(strtolower($e->getMessage()), 'unique constraint failed');
    }

    private function toDomain(PayableAccrualModel $m): PayableAccrual
    {
        $currency = $m->currency;

        return PayableAccrual::reconstitute(
            id: $m->id,
            type: AccrualType::from($m->type),
            merchantType: $m->merchant_type,
            merchantId: $m->merchant_id,
            orderId: $m->order_id,
            paymentId: $m->payment_id,
            refundId: $m->refund_id,
            gross: new Money($m->gross_minor, $currency),
            commission: new Money($m->commission_minor, $currency),
            fee: new Money($m->fee_minor, $currency),
            net: new Money($m->net_minor, $currency),
            commissionRateBps: $m->commission_rate_bps,
            ledgerPosted: $m->ledger_posted,
            correlationId: (string) $m->correlation_id,
            accruedAt: DateTimeImmutable::createFromInterface($m->accrued_at),
        );
    }
}
