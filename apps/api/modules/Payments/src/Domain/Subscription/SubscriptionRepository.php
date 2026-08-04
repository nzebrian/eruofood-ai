<?php

declare(strict_types=1);

namespace EruoFood\Payments\Domain\Subscription;

use DateTimeImmutable;

/** Persistence port for {@see Subscription}. */
interface SubscriptionRepository
{
    public function nextIdentity(): string;

    public function findById(string $id): ?Subscription;

    /** @return list<Subscription> */
    public function forUser(string $userId): array;

    /** @return list<Subscription> subscriptions due to bill on or before $now */
    public function due(DateTimeImmutable $now): array;

    public function save(Subscription $subscription): void;
}
