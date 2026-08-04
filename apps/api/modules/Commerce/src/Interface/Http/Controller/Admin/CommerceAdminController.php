<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Interface\Http\Controller\Admin;

use EruoFood\Commerce\Application\Service\AdminDashboardService;
use EruoFood\Commerce\Application\Service\CommercePresenter;
use EruoFood\Commerce\Application\Service\OrderService;
use EruoFood\Commerce\Domain\Enum\OrderStatus;
use EruoFood\Commerce\Domain\Order\Order;
use EruoFood\Commerce\Interface\Http\Concerns\RespondsWithData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Admin monitoring: all orders and per-store sales reporting. */
final readonly class CommerceAdminController
{
    use RespondsWithData;

    public function __construct(
        private OrderService $orders,
        private AdminDashboardService $dashboard,
        private CommercePresenter $presenter,
    ) {
    }

    public function orders(Request $request): JsonResponse
    {
        $status = $request->filled('status') ? OrderStatus::tryFrom((string) $request->string('status')) : null;
        $page = $this->orders->all($status, (int) $request->integer('page', 1), (int) $request->integer('per_page', 20));

        return $this->paginated($page, fn (Order $o): array => $this->presenter->orderSummary($o));
    }

    public function storeSales(string $storeId): JsonResponse
    {
        return $this->data($this->dashboard->salesForStore($storeId)->toArray());
    }
}
