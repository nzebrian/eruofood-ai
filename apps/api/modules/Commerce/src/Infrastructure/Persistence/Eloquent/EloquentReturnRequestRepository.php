<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use EruoFood\Commerce\Domain\Enum\ReturnStatus;
use EruoFood\Commerce\Domain\Order\ReturnRequest;
use EruoFood\Commerce\Domain\Order\ReturnRequestRepository;
use EruoFood\Commerce\Infrastructure\Persistence\Eloquent\Model\ReturnRequestModel;
use EruoFood\Shared\Domain\Paginated;
use EruoFood\Shared\Domain\ValueObject\Money;
use Illuminate\Support\Str;

final class EloquentReturnRequestRepository implements ReturnRequestRepository
{
    public function __construct(private readonly string $currency)
    {
    }

    public function nextIdentity(): string
    {
        return (string) Str::orderedUuid();
    }

    public function findById(string $id): ?ReturnRequest
    {
        $m = ReturnRequestModel::query()->find($id);

        return $m !== null ? $this->toDomain($m) : null;
    }

    public function existsForOrder(string $orderId): bool
    {
        return ReturnRequestModel::query()->where('order_id', $orderId)->exists();
    }

    public function forCustomer(string $customerUserId, int $page, int $perPage): Paginated
    {
        $paginator = ReturnRequestModel::query()
            ->where('customer_user_id', $customerUserId)
            ->orderByDesc('requested_at')
            ->paginate(perPage: $perPage, page: $page);

        return new Paginated(
            array_values(array_map(fn (ReturnRequestModel $m): ReturnRequest => $this->toDomain($m), $paginator->items())),
            $paginator->total(),
            $page,
            $perPage,
        );
    }

    public function all(int $page, int $perPage): Paginated
    {
        $paginator = ReturnRequestModel::query()
            ->orderByDesc('requested_at')
            ->paginate(perPage: $perPage, page: $page);

        return new Paginated(
            array_values(array_map(fn (ReturnRequestModel $m): ReturnRequest => $this->toDomain($m), $paginator->items())),
            $paginator->total(),
            $page,
            $perPage,
        );
    }

    public function save(ReturnRequest $request): void
    {
        $model = ReturnRequestModel::query()->find($request->id()) ?? new ReturnRequestModel();
        $model->id = $request->id();
        $model->order_id = $request->orderId();
        $model->customer_user_id = $request->customerUserId();
        $model->reason = $request->reason();
        $model->refund_minor = $request->refundAmount()->minorUnits;
        $model->currency = $request->refundAmount()->currency;
        $model->status = $request->status()->value;
        $model->resolution_note = $request->resolutionNote();
        $model->requested_at = $request->requestedAt();
        $model->resolved_at = $request->resolvedAt();
        $model->save();
    }

    private function toDomain(ReturnRequestModel $m): ReturnRequest
    {
        $currency = $m->currency ?: $this->currency;

        return ReturnRequest::reconstitute(
            id: $m->id,
            orderId: $m->order_id,
            customerUserId: $m->customer_user_id,
            reason: $m->reason,
            refundAmount: new Money($m->refund_minor, $currency),
            status: ReturnStatus::from($m->status),
            resolutionNote: $m->resolution_note,
            requestedAt: DateTimeImmutable::createFromInterface($m->requested_at),
            resolvedAt: $m->resolved_at !== null ? DateTimeImmutable::createFromInterface($m->resolved_at) : null,
        );
    }
}
