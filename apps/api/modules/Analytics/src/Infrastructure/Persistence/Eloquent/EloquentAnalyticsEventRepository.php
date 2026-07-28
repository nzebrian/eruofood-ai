<?php

declare(strict_types=1);

namespace EruoFood\Analytics\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use EruoFood\Analytics\Domain\Metric\AnalyticsEvent;
use EruoFood\Analytics\Domain\Metric\AnalyticsEventRepository;
use EruoFood\Analytics\Infrastructure\Persistence\Eloquent\Model\AnalyticsEventModel;
use Illuminate\Support\Str;

final class EloquentAnalyticsEventRepository implements AnalyticsEventRepository
{
    public function nextIdentity(): string
    {
        return (string) Str::orderedUuid();
    }

    public function append(AnalyticsEvent $event): void
    {
        $model = new AnalyticsEventModel();
        $model->id = $event->id();
        $model->name = $event->name();
        $model->category = $event->category()->value;
        $model->actor_id = $event->actorId();
        $model->value = $event->value();
        $model->dimensions = $event->dimensions();
        $model->occurred_at = $event->occurredAt();
        $model->save();
    }

    public function countSince(DateTimeImmutable $since): int
    {
        return (int) AnalyticsEventModel::query()->where('occurred_at', '>=', $since->format('Y-m-d H:i:s'))->count();
    }
}
