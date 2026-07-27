<?php

declare(strict_types=1);

namespace EruoFood\Payments\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use EruoFood\Payments\Domain\Enum\SettlementStatus;
use EruoFood\Payments\Domain\Settlement\Settlement;
use EruoFood\Payments\Domain\Settlement\SettlementRepository;
use EruoFood\Payments\Infrastructure\Persistence\Eloquent\Model\SettlementModel;
use EruoFood\Shared\Domain\Paginated;
use EruoFood\Shared\Domain\ValueObject\Money;
use Illuminate\Support\Str;

final class EloquentSettlementRepository implements SettlementRepository
{
    public function __construct(private readonly string $currency)
    {
    }

    public function nextIdentity(): string
    {
        return (string) Str::orderedUuid();
    }

    public function findById(string $id): ?Settlement
    {
        $m = SettlementModel::query()->find($id);

        return $m !== null ? $this->toDomain($m) : null;
    }

    public function forPayee(string $payeeType, string $payeeId, int $page, int $perPage): Paginated
    {
        $paginator = SettlementModel::query()->where('payee_type', $payeeType)->where('payee_id', $payeeId)
            ->orderByDesc('created_at')->paginate(perPage: $perPage, page: $page);

        return $this->paginate($paginator, $page, $perPage);
    }

    public function all(int $page, int $perPage): Paginated
    {
        $paginator = SettlementModel::query()->orderByDesc('created_at')->paginate(perPage: $perPage, page: $page);

        return $this->paginate($paginator, $page, $perPage);
    }

    public function save(Settlement $settlement): void
    {
        $model = SettlementModel::query()->find($settlement->id()) ?? new SettlementModel();
        $model->id = $settlement->id();
        $model->payee_type = $settlement->payeeType();
        $model->payee_id = $settlement->payeeId();
        $model->gross_minor = $settlement->gross()->minorUnits;
        $model->commission_minor = $settlement->commission()->minorUnits;
        $model->fees_minor = $settlement->fees()->minorUnits;
        $model->net_minor = $settlement->net()->minorUnits;
        $model->currency = $settlement->net()->currency;
        $model->status = $settlement->status()->value;
        $model->payout_id = $settlement->payoutId();
        $model->period_start = $settlement->periodStart();
        $model->period_end = $settlement->periodEnd();
        $model->created_at = $settlement->createdAt();
        $model->completed_at = $settlement->completedAt();
        $model->save();
    }

    /**
     * @param \Illuminate\Pagination\LengthAwarePaginator<int, SettlementModel> $paginator
     * @return Paginated<Settlement>
     */
    private function paginate(\Illuminate\Pagination\LengthAwarePaginator $paginator, int $page, int $perPage): Paginated
    {
        return new Paginated(
            array_map(fn (SettlementModel $m): Settlement => $this->toDomain($m), $paginator->items()),
            $paginator->total(),
            $page,
            $perPage,
        );
    }

    private function toDomain(SettlementModel $m): Settlement
    {
        $c = $m->currency ?: $this->currency;

        return Settlement::reconstitute(
            id: $m->id,
            payeeType: $m->payee_type,
            payeeId: $m->payee_id,
            gross: new Money((int) $m->gross_minor, $c),
            commission: new Money((int) $m->commission_minor, $c),
            fees: new Money((int) $m->fees_minor, $c),
            net: new Money((int) $m->net_minor, $c),
            status: SettlementStatus::from($m->status),
            payoutId: $m->payout_id,
            periodStart: DateTimeImmutable::createFromInterface($m->period_start),
            periodEnd: DateTimeImmutable::createFromInterface($m->period_end),
            createdAt: DateTimeImmutable::createFromInterface($m->created_at),
            completedAt: $m->completed_at !== null ? DateTimeImmutable::createFromInterface($m->completed_at) : null,
        );
    }
}
