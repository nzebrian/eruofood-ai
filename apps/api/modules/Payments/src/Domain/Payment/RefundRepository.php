<?php

declare(strict_types=1);

namespace EruoFood\Payments\Domain\Payment;

use EruoFood\Shared\Domain\Paginated;

/** Persistence port for the {@see Refund} aggregate. */
interface RefundRepository
{
    public function nextIdentity(): string;

    public function findById(string $id): ?Refund;

    /** @return list<Refund> */
    public function forPayment(string $paymentId): array;

    /**
     * Total minor units already claimed against a payment — every refund that is
     * completed *or* still pending with the provider.
     *
     * Pending refunds count because they are reservations: the money is promised
     * even though the provider has not confirmed it. Excluding them would let a
     * second request refund the same balance while the first is still in flight.
     * Failed refunds are excluded, which releases their reservation.
     */
    public function reservedMinorFor(string $paymentId): int;

    /** @return Paginated<Refund> */
    public function all(int $page, int $perPage): Paginated;

    public function save(Refund $refund): void;
}
