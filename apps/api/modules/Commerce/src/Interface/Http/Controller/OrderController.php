<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Interface\Http\Controller;

use EruoFood\Commerce\Application\Service\CommercePresenter;
use EruoFood\Commerce\Application\Service\OrderService;
use EruoFood\Commerce\Domain\Enum\OrderStatus;
use EruoFood\Commerce\Domain\Order\Order;
use EruoFood\Commerce\Interface\Http\Concerns\ResolvesAuthUser;
use EruoFood\Commerce\Interface\Http\Concerns\RespondsWithData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Order history & detail (customer), fulfilment (seller) and invoices. */
final readonly class OrderController
{
    use RespondsWithData;
    use ResolvesAuthUser;

    public function __construct(
        private OrderService $orders,
        private CommercePresenter $presenter,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $page = $this->orders->history(
            $this->currentUserId($request),
            (int) $request->integer('page', 1),
            (int) $request->integer('per_page', 20),
        );

        return $this->paginated($page, fn (Order $o): array => $this->presenter->orderSummary($o));
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $order = $this->orders->forCustomer($id, $this->currentUserId($request), $this->actorIsAdmin($request));

        return $this->data($this->presenter->order($order));
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $order = $this->orders->cancel($id, $this->currentUserId($request), $this->actorIsAdmin($request));

        return $this->data($this->presenter->order($order));
    }

    public function advance(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:paid,processing,shipped,delivered'],
        ]);
        $order = $this->orders->advance(
            $id,
            OrderStatus::from((string) $data['status']),
            $this->currentUserId($request),
            $this->actorIsAdmin($request),
        );

        return $this->data($this->presenter->order($order));
    }

    public function invoice(Request $request, string $id): JsonResponse
    {
        $invoice = $this->orders->invoice($id, $this->currentUserId($request), $this->actorIsAdmin($request));

        return $this->data($invoice->toArray());
    }

    public function storeOrders(Request $request, string $storeId): JsonResponse
    {
        $status = $request->filled('status') ? OrderStatus::tryFrom((string) $request->string('status')) : null;
        $page = $this->orders->forStore(
            $storeId,
            $this->currentUserId($request),
            $this->actorIsAdmin($request),
            $status,
            (int) $request->integer('page', 1),
            (int) $request->integer('per_page', 20),
        );

        return $this->paginated($page, fn (Order $o): array => $this->presenter->order($o));
    }
}
