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

    /** @return Paginated<Refund> */
    public function all(int $page, int $perPage): Paginated;

    public function save(Refund $refund): void;
}
