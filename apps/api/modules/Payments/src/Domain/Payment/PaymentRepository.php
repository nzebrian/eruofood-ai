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

    /**
     * Read the payment holding an exclusive row lock until the surrounding
     * transaction ends.
     *
     * Used wherever a decision is made from the payment's own totals and then
     * written back — refunding, capturing — so two concurrent requests cannot
     * both read the same refundable balance and both act on it.
     */
    public function findByIdForUpdate(string $id): ?Payment;

    public function findByReference(string $reference): ?Payment;

    public function findByIdempotencyKey(string $key): ?Payment;

    public function findByProviderReference(string $provider, string $reference): ?Payment;

    /**
     * The succeeded payment for an order, if there is one.
     *
     * Returns the earliest succeeded payment rather than the latest. An order
     * with two succeeded payments is a double-charge that must be reconciled,
     * not silently resolved in favour of whichever came last — and accruing
     * against a stable choice makes the duplicate visible as an orphan rather
     * than changing which payment the merchant was paid for between runs.
     */
    public function findCapturedForOrder(string $orderId): ?Payment;

    /** @return Paginated<Payment> */
    public function forPayer(string $payerUserId, int $page, int $perPage): Paginated;

    /** @return Paginated<Payment> */
    public function all(?PaymentStatus $status, int $page, int $perPage): Paginated;

    /** @return array{count: int, gross_minor: int} */
    public function capturedTotals(): array;

    public function save(Payment $payment): void;
}
