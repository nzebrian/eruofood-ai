<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Interface\Http\Controller;

use EruoFood\Marketplace\Application\Service\MarketplacePresenter;
use EruoFood\Marketplace\Application\Service\OrderService;
use EruoFood\Marketplace\Domain\Enum\OrderStatus;
use EruoFood\Marketplace\Domain\Order\Order;
use EruoFood\Marketplace\Interface\Http\Concerns\ResolvesAuthUser;
use EruoFood\Marketplace\Interface\Http\Concerns\RespondsWithData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Order tracking, history and vendor-side order management. */
final readonly class OrderController
{
    use RespondsWithData;
    use ResolvesAuthUser;

    public function __construct(
        private OrderService $orders,
        private MarketplacePresenter $presenter,
    ) {
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $order = $this->orders->get($this->currentUserId($request), $this->actorIsAdmin($request), $id);

        return $this->data($this->presenter->order($order));
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

    public function cancel(Request $request, string $id): JsonResponse
    {
        $order = $this->orders->cancel($this->currentUserId($request), $this->actorIsAdmin($request), $id);

        return $this->data($this->presenter->order($order));
    }

    public function vendorIndex(Request $request, string $vendorId): JsonResponse
    {
        $status = $request->filled('status') ? OrderStatus::tryFrom((string) $request->string('status')) : null;
        $page = $this->orders->vendorOrders(
            $this->currentUserId($request),
            $this->actorIsAdmin($request),
            $vendorId,
            $status,
            (int) $request->integer('page', 1),
            (int) $request->integer('per_page', 20),
        );

        return $this->paginated($page, fn (Order $o): array => $this->presenter->orderSummary($o));
    }

    public function advance(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'in:confirmed,preparing,ready,dispatched,delivered'],
        ]);
        $order = $this->orders->advanceStatus(
            $this->currentUserId($request),
            $this->actorIsAdmin($request),
            $id,
            OrderStatus::from((string) $validated['status']),
        );

        return $this->data($this->presenter->order($order));
    }
}
