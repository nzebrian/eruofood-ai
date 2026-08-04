<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Application\Service;

use DateTimeImmutable;
use EruoFood\Commerce\Domain\Enum\OrderStatus;
use EruoFood\Commerce\Domain\Enum\ReturnStatus;
use EruoFood\Commerce\Domain\Exception\CommerceConflict;
use EruoFood\Commerce\Domain\Exception\CommerceInvalidState;
use EruoFood\Commerce\Domain\Exception\CommerceNotFound;
use EruoFood\Commerce\Domain\Exception\NotResourceOwner;
use EruoFood\Commerce\Domain\Order\OrderRepository;
use EruoFood\Commerce\Domain\Order\ReturnRequest;
use EruoFood\Commerce\Domain\Order\ReturnRequestRepository;
use EruoFood\Shared\Domain\Paginated;

/**
 * Returns & refunds. A customer may raise one return per delivered order;
 * admins approve/reject and mark refunded (which also marks the order returned).
 */
final readonly class ReturnService
{
    public function __construct(
        private ReturnRequestRepository $returns,
        private OrderRepository $orders,
    ) {
    }

    public function request(string $orderId, string $userId, string $reason): ReturnRequest
    {
        $order = $this->orders->findById($orderId) ?? throw CommerceNotFound::of('order', $orderId);
        if (! $order->isForCustomer($userId)) {
            throw new NotResourceOwner();
        }
        if ($order->status() !== OrderStatus::Delivered) {
            throw new CommerceInvalidState('Only delivered orders can be returned.');
        }
        if ($this->returns->existsForOrder($orderId)) {
            throw new CommerceConflict('A return has already been requested for this order.');
        }

        $request = ReturnRequest::open(
            $this->returns->nextIdentity(),
            $orderId,
            $userId,
            $reason,
            $order->total(),
            new DateTimeImmutable(),
        );
        $this->returns->save($request);

        return $request;
    }

    public function resolve(string $returnId, ReturnStatus $next, ?string $note): ReturnRequest
    {
        $request = $this->returns->findById($returnId) ?? throw CommerceNotFound::of('return', $returnId);
        $request->transitionTo($next, $note, new DateTimeImmutable());
        $this->returns->save($request);

        // A refund closes the loop by marking the order returned.
        if ($next === ReturnStatus::Refunded) {
            $order = $this->orders->findById($request->orderId());
            if ($order !== null && $order->status() === OrderStatus::Delivered) {
                $order->markReturned(new DateTimeImmutable());
                $this->orders->save($order);
            }
        }

        return $request;
    }

    /** @return Paginated<ReturnRequest> */
    public function forCustomer(string $userId, int $page, int $perPage): Paginated
    {
        return $this->returns->forCustomer($userId, $page, $perPage);
    }

    /** @return Paginated<ReturnRequest> */
    public function all(int $page, int $perPage): Paginated
    {
        return $this->returns->all($page, $perPage);
    }
}
