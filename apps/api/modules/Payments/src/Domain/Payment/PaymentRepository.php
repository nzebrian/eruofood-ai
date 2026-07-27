<?php

declare(strict_types=1);

namespace EruoFood\Payments\Domain\Payment;

use EruoFood\Payments\Domain\Enum\PaymentStatus;
use EruoFood\Shared\Domain\Paginated;

/** Persistence port for the {@see Payment} aggregate. */
interface PaymentRepository
{
    public function nextIdentity(): string;

    public function nextReference(): string;

    public function findById(string $id): ?Payment;

    public function findByReference(string $reference): ?Payment;

    public function findByIdempotencyKey(string $key): ?Payment;

    public function findByProviderReference(string $provider, string $reference): ?Payment;

    /** @return Paginated<Payment> */
    public function forPayer(string $payerUserId, int $page, int $perPage): Paginated;

    /** @return Paginated<Payment> */
    public function all(?PaymentStatus $status, int $page, int $perPage): Paginated;

    /** @return array{count: int, gross_minor: int} */
    public function capturedTotals(): array;

    public function save(Payment $payment): void;
}
