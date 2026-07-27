<?php

declare(strict_types=1);

namespace EruoFood\Payments\Infrastructure\Persistence\Eloquent;

use EruoFood\Payments\Domain\Webhook\WebhookEventRepository;
use EruoFood\Payments\Infrastructure\Persistence\Eloquent\Model\WebhookEventModel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

final class EloquentWebhookEventRepository implements WebhookEventRepository
{
    public function seen(string $provider, string $eventId): bool
    {
        return WebhookEventModel::query()->where('provider', $provider)->where('event_id', $eventId)->exists();
    }

    public function record(string $provider, string $eventId, string $type): void
    {
        $model = new WebhookEventModel();
        $model->id = (string) Str::orderedUuid();
        $model->provider = $provider;
        $model->event_id = $eventId;
        $model->type = $type;
        $model->created_at = Carbon::now();
        $model->save();
    }
}
