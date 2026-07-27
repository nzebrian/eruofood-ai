<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use EruoFood\Commerce\Domain\Enum\OrderStatus;
use EruoFood\Commerce\Domain\Order\Order;
use EruoFood\Commerce\Domain\Order\OrderLine;
use EruoFood\Commerce\Domain\Order\OrderRepository;
use EruoFood\Commerce\Domain\ValueObject\Address;
use EruoFood\Commerce\Infrastructure\Persistence\Eloquent\Model\OrderModel;
use EruoFood\Shared\Domain\Paginated;
use EruoFood\Shared\Domain\ValueObject\Money;
use Illuminate\Support\Str;

final class EloquentOrderRepository implements OrderRepository
{
    public function __construct(private readonly string $currency)
    {
    }

    public function nextIdentity(): string
    {
        return (string) Str::orderedUuid();
    }

    public function nextReference(): string
    {
        return 'EF-'.strtoupper(Str::random(4)).'-'.strtoupper(Str::random(4));
    }

    public function findById(string $id): ?Order
    {
        $m = OrderModel::query()->find($id);

        return $m !== null ? $this->toDomain($m) : null;
    }

    public function forCustomer(string $customerUserId, int $page, int $perPage): Paginated
    {
        $paginator = OrderModel::query()
            ->where('customer_user_id', $customerUserId)
            ->orderByDesc('placed_at')
            ->paginate(perPage: $perPage, page: $page);

        return $this->paginate($paginator, $page, $perPage);
    }

    public function forStore(string $storeId, ?OrderStatus $status, int $page, int $perPage): Paginated
    {
        $query = OrderModel::query()->whereJsonContains('store_ids', $storeId);
        if ($status !== null) {
            $query->where('status', $status->value);
        }
        $paginator = $query->orderByDesc('placed_at')->paginate(perPage: $perPage, page: $page);

        return $this->paginate($paginator, $page, $perPage);
    }

    public function all(?OrderStatus $status, int $page, int $perPage): Paginated
    {
        $query = OrderModel::query();
        if ($status !== null) {
            $query->where('status', $status->value);
        }
        $paginator = $query->orderByDesc('placed_at')->paginate(perPage: $perPage, page: $page);

        return $this->paginate($paginator, $page, $perPage);
    }

    public function salesSummaryForStore(string $storeId): array
    {
        $query = OrderModel::query()
            ->whereJsonContains('store_ids', $storeId)
            ->whereNotIn('status', [OrderStatus::Cancelled->value, OrderStatus::Returned->value]);

        return [
            'orders' => (int) $query->count(),
            'revenue_minor' => (int) $query->sum('total_minor'),
        ];
    }

    public function save(Order $order): void
    {
        $model = OrderModel::query()->find($order->id()) ?? new OrderModel();
        $model->id = $order->id();
        $model->reference = $order->reference();
        $model->customer_user_id = $order->customerUserId();
        $model->store_ids = array_values(array_unique(array_map(
            static fn (OrderLine $l): string => $l->storeId,
            $order->lines(),
        )));
        $model->lines = array_map(static fn (OrderLine $l): array => $l->toArray(), $order->lines());
        $model->subtotal_minor = $order->subtotal()->minorUnits;
        $model->discount_minor = $order->discount()->minorUnits;
        $model->tax_minor = $order->tax()->minorUnits;
        $model->shipping_minor = $order->shipping()->minorUnits;
        $model->total_minor = $order->total()->minorUnits;
        $model->currency = $order->total()->currency;
        $model->coupon_code = $order->couponCode();
        $model->pickup = $order->isPickup();
        $model->shipping_address = $order->shippingAddress()?->toArray();
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
    private function paginate(\Illuminate\Pagination\LengthAwarePaginator $paginator, int $page, int $perPage): Paginated
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
        $currency = $m->currency ?: $this->currency;
        $lines = array_map(
            static fn (array $row): OrderLine => OrderLine::fromArray($row, $currency),
            $m->lines ?? [],
        );

        return Order::reconstitute(
            id: $m->id,
            reference: $m->reference,
            customerUserId: $m->customer_user_id,
            lines: array_values($lines),
            subtotal: new Money($m->subtotal_minor, $currency),
            discount: new Money($m->discount_minor, $currency),
            tax: new Money($m->tax_minor, $currency),
            shipping: new Money($m->shipping_minor, $currency),
            total: new Money($m->total_minor, $currency),
            couponCode: $m->coupon_code,
            pickup: $m->pickup,
            shippingAddress: $m->shipping_address !== null ? Address::fromArray($m->shipping_address) : null,
            scheduledFor: $m->scheduled_for !== null ? DateTimeImmutable::createFromInterface($m->scheduled_for) : null,
            note: $m->note,
            status: OrderStatus::from($m->status),
            statusHistory: $m->status_history ?? [],
            placedAt: DateTimeImmutable::createFromInterface($m->placed_at),
        );
    }
}
