<?php

declare(strict_types=1);

namespace EruoFood\Payments\Domain\Method;

/** Persistence port for {@see SavedPaymentMethod}. */
interface SavedPaymentMethodRepository
{
    public function nextIdentity(): string;

    public function findById(string $id): ?SavedPaymentMethod;

    /** @return list<SavedPaymentMethod> */
    public function forUser(string $userId): array;

    public function clearDefaultFor(string $userId): void;

    public function save(SavedPaymentMethod $method): void;

    public function delete(string $id): void;
}
