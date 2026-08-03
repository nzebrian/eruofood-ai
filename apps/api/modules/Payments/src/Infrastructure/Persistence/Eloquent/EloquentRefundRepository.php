<?php

declare(strict_types=1);

namespace EruoFood\Payments\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use EruoFood\Payments\Domain\Enum\RefundStatus;
use EruoFood\Payments\Domain\Payment\Refund;
use EruoFood\Payments\Domain\Payment\RefundRepository;
use EruoFood\Payments\Infrastructure\Persistence\Eloquent\Model\RefundModel;
use EruoFood\Shared\Domain\Paginated;
use EruoFood\Shared\Domain\ValueObject\Money;
use Illuminate\Support\Str;

final class EloquentRefundRepository implements RefundRepository
{
    public function __construct(private readonly string $currency)
    {
    }

    public function nextIdentity(): string
    {
        return (string) Str::orderedUuid();
    }

    public function findById(string $id): ?Refund
    {
        $m = RefundModel::query()->find($id);

        return $m !== null ? $this->toDomain($m) : null;
    }

    public function forPayment(string $paymentId): array
    {
        return array_values(array_map(
            fn (RefundModel $m): Refund => $this->toDomain($m),
            RefundModel::query()->where('payment_id', $paymentId)->orderByDesc('created_at')->get()->all(),
        ));
    }

    public function all(int $page, int $perPage): Paginated
    {
        $paginator = RefundModel::query()->orderByDesc('created_at')->paginate(perPage: $perPage, page: $page);

        return new Paginated(
            array_values(array_map(fn (RefundModel $m): Refund => $this->toDomain($m), $paginator->items())),
            $paginator->total(),
            $page,
            $perPage,
        );
    }

    public function save(Refund $refund): void
    {
        $model = RefundModel::query()->find($refund->id()) ?? new RefundModel();
        $model->id = $refund->id();
        $model->payment_id = $refund->paymentId();
        $model->order_id = $refund->orderId();
        $model->amount_minor = $refund->amount()->minorUnits;
        $model->currency = $refund->amount()->currency;
        $model->partial = $refund->isPartial();
        $model->reason = $refund->reason();
        $model->status = $refund->status()->value;
        $model->created_at = $refund->createdAt();
        $model->completed_at = $refund->completedAt();
        $model->save();
    }

    private function toDomain(RefundModel $m): Refund
    {
        return Refund::reconstitute(
            id: $m->id,
            paymentId: $m->payment_id,
            orderId: $m->order_id,
            amount: new Money((int) $m->amount_minor, $m->currency ?: $this->currency),
            partial: $m->partial,
            reason: $m->reason,
            status: RefundStatus::from($m->status),
            createdAt: DateTimeImmutable::createFromInterface($m->created_at),
            completedAt: $m->completed_at !== null ? DateTimeImmutable::createFromInterface($m->completed_at) : null,
        );
    }
}
