<?php

declare(strict_types=1);

namespace EruoFood\Payments\Application\Service;

use DateTimeImmutable;
use EruoFood\Payments\Domain\Exception\PaymentsNotAuthorized;
use EruoFood\Payments\Domain\Exception\PaymentsNotFound;
use EruoFood\Payments\Domain\Subscription\Subscription;
use EruoFood\Payments\Domain\Subscription\SubscriptionRepository;
use EruoFood\Shared\Domain\ValueObject\Money;

/** Recurring subscription billing (architecture-ready). */
final readonly class SubscriptionService
{
    public function __construct(
        private SubscriptionRepository $subscriptions,
        private string $currency,
    ) {
    }

    public function start(string $userId, string $plan, int $amountMinor, string $interval): Subscription
    {
        $subscription = Subscription::start(
            $this->subscriptions->nextIdentity(),
            $userId,
            $plan,
            new Money($amountMinor, $this->currency),
            $interval === 'weekly' ? 'weekly' : 'monthly',
            new DateTimeImmutable(),
        );
        $this->subscriptions->save($subscription);

        return $subscription;
    }

    public function cancel(string $id, string $userId): Subscription
    {
        $subscription = $this->owned($id, $userId);
        $subscription->cancel();
        $this->subscriptions->save($subscription);

        return $subscription;
    }

    /** @return list<Subscription> */
    public function forUser(string $userId): array
    {
        return $this->subscriptions->forUser($userId);
    }

    private function owned(string $id, string $userId): Subscription
    {
        $subscription = $this->subscriptions->findById($id) ?? throw PaymentsNotFound::of('subscription', $id);
        if (! $subscription->isOwnedBy($userId)) {
            throw new PaymentsNotAuthorized();
        }

        return $subscription;
    }
}
