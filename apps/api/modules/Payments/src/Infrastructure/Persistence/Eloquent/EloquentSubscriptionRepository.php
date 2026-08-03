<?php

declare(strict_types=1);

namespace EruoFood\Payments\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use EruoFood\Payments\Domain\Enum\SubscriptionStatus;
use EruoFood\Payments\Domain\Subscription\Subscription;
use EruoFood\Payments\Domain\Subscription\SubscriptionRepository;
use EruoFood\Payments\Infrastructure\Persistence\Eloquent\Model\SubscriptionModel;
use EruoFood\Shared\Domain\ValueObject\Money;
use Illuminate\Support\Str;

final class EloquentSubscriptionRepository implements SubscriptionRepository
{
    public function __construct(private readonly string $currency)
    {
    }

    public function nextIdentity(): string
    {
        return (string) Str::orderedUuid();
    }

    public function findById(string $id): ?Subscription
    {
        $m = SubscriptionModel::query()->find($id);

        return $m !== null ? $this->toDomain($m) : null;
    }

    public function forUser(string $userId): array
    {
        return array_map(
            fn (SubscriptionModel $m): Subscription => $this->toDomain($m),
            SubscriptionModel::query()->where('user_id', $userId)->orderByDesc('created_at')->get()->all(),
        );
    }

    public function due(DateTimeImmutable $now): array
    {
        return array_map(
            fn (SubscriptionModel $m): Subscription => $this->toDomain($m),
            SubscriptionModel::query()->where('status', SubscriptionStatus::Active->value)
                ->where('next_billing_at', '<=', $now->format('Y-m-d H:i:s'))->get()->all(),
        );
    }

    public function save(Subscription $subscription): void
    {
        $model = SubscriptionModel::query()->find($subscription->id()) ?? new SubscriptionModel();
        $model->id = $subscription->id();
        $model->user_id = $subscription->userId();
        $model->plan = $subscription->plan();
        $model->amount_minor = $subscription->amount()->minorUnits;
        $model->currency = $subscription->amount()->currency;
        $model->interval = $subscription->interval();
        $model->status = $subscription->status()->value;
        $model->next_billing_at = $subscription->nextBillingAt();
        $model->created_at = $subscription->createdAt();
        $model->save();
    }

    private function toDomain(SubscriptionModel $m): Subscription
    {
        return Subscription::reconstitute(
            id: $m->id,
            userId: $m->user_id,
            plan: $m->plan,
            amount: new Money((int) $m->amount_minor, $m->currency ?: $this->currency),
            interval: $m->interval,
            status: SubscriptionStatus::from($m->status),
            nextBillingAt: DateTimeImmutable::createFromInterface($m->next_billing_at),
            createdAt: DateTimeImmutable::createFromInterface($m->created_at),
        );
    }
}
