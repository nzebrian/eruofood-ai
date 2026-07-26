<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use EruoFood\Marketplace\Domain\Enum\FulfilmentType;
use EruoFood\Marketplace\Domain\Enum\OrderStatus;
use EruoFood\Marketplace\Domain\Order\Order;
use EruoFood\Marketplace\Domain\Order\OrderLine;
use EruoFood\Marketplace\Domain\Order\OrderRepository;
use EruoFood\Marketplace\Domain\ValueObject\Address;
use EruoFood\Marketplace\Infrastructure\Persistence\Eloquent\Model\OrderModel;
use EruoFood\Shared\Domain\Paginated;
use EruoFood\Shared\Domain\ValueObject\Money;
use Illuminate\Support\Str;

final class EloquentOrderRepository implements OrderRepository
{
    public function nextIdentity(): string
    {
        return (string) Str::orderedUuid();
    }

    public function nextReference(): string
    {
        return 'EF-'.strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
    }

    public function findById(string $id): ?Order
    {
        $m = OrderModel::query()->find($id);

        return $m !== null ? $this->toDomain($m) : null;
    }

    public function forCustomer(string $userId, int $page, int $perPage): Paginated
    {
        $paginator = OrderModel::query()
            ->where('customer_user_id', $userId)
            ->orderByDesc('placed_at')
            ->paginate(perPage: $perPage, page: $page);

        return $this->paginate($paginator, $page, $perPage);
    }

    public function forVendor(string $vendorId, ?OrderStatus $status, int $page, int $perPage): Paginated
    {
        $query = OrderModel::query()->where('vendor_id', $vendorId);
        if ($status !== null) {
            $query->where('status', $status->value);
        }
        $paginator = $query->orderByDesc('placed_at')->paginate(perPage: $perPage, page: $page);

        return $this->paginate($paginator, $page, $perPage);
    }

    public function salesSummary(string $vendorId): array
    {
        $row = OrderModel::query()
            ->where('vendor_id', $vendorId)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END), 0) as delivered")
            ->selectRaw("COALESCE(SUM(CASE WHEN status IN ('delivered','cancelled') THEN 0 ELSE 1 END), 0) as pending")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'delivered' THEN total_minor ELSE 0 END), 0) as revenue_minor")
            ->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'delivered' => (int) ($row->delivered ?? 0),
            'pending' => (int) ($row->pending ?? 0),
            'revenue_minor' => (int) ($row->revenue_minor ?? 0),
        ];
    }

    public function save(Order $order): void
    {
        $model = OrderModel::query()->find($order->id()) ?? new OrderModel();
        $model->id = $order->id();
        $model->reference = $order->reference();
        $model->customer_user_id = $order->customerUserId();
        $model->vendor_id = $order->vendorId();
        $model->lines = array_map(static fn (OrderLine $l): array => $l->toArray(), $order->lines());
        $model->subtotal_minor = $order->subtotal()->minorUnits;
        $model->delivery_fee_minor = $order->deliveryFee()->minorUnits;
        $model->total_minor = $order->total()->minorUnits;
        $model->currency = $order->total()->currency;
        $model->fulfilment = $order->fulfilment()->value;
        $model->delivery_address = $order->deliveryAddress()?->toArray();
        $model->scheduled_for = $order->scheduledFor();
        $model->note = $order->note();
        $model->status = $order->status()->value;
        $model->status_history = $order->statusHistory();
        $model->placed_at = $order->placedAt();
        $model->save();
    }

    /**
     * @param \Illuminate\Pagination\LengthAwarePaginator<int, OrderModel> $paginator
     * @return Paginated<Order>
     */
    private function paginate($paginator, int $page, int $perPage): Paginated
    {
        return new Paginated(
            array_map(fn (OrderModel $m): Order => $this->toDomain($m), $paginator->items()),
            $paginator->total(),
            $page,
            $perPage,
        );
    }

    private function toDomain(OrderModel $m): Order
    {
        $currency = $m->currency;

        return Order::reconstitute(
            id: $m->id,
            reference: $m->reference,
            customerUserId: $m->customer_user_id,
            vendorId: $m->vendor_id,
            lines: array_map(static fn (array $l): OrderLine => OrderLine::fromArray($l, $currency), $m->lines ?? []),
            subtotal: new Money($m->subtotal_minor, $currency),
            deliveryFee: new Money($m->delivery_fee_minor, $currency),
            total: new Money($m->total_minor, $currency),
            fulfilment: FulfilmentType::from($m->fulfilment),
            deliveryAddress: $m->delivery_address !== null ? Address::fromArray($m->delivery_address) : null,
            scheduledFor: $m->scheduled_for !== null ? DateTimeImmutable::createFromInterface($m->scheduled_for) : null,
            note: $m->note,
            status: OrderStatus::from($m->status),
            statusHistory: $m->status_history ?? [],
            placedAt: DateTimeImmutable::createFromInterface($m->placed_at),
        );
    }
}
