<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use EruoFood\PublicApi\Domain\Enum\DeliveryStatus;
use EruoFood\PublicApi\Domain\Enum\WebhookStatus;
use EruoFood\PublicApi\Domain\Webhook\Webhook;
use EruoFood\PublicApi\Domain\Webhook\WebhookDelivery;
use EruoFood\PublicApi\Domain\Webhook\WebhookRepository;
use EruoFood\PublicApi\Infrastructure\Persistence\Eloquent\Model\WebhookDeliveryModel;
use EruoFood\PublicApi\Infrastructure\Persistence\Eloquent\Model\WebhookModel;
use Illuminate\Support\Str;

final class EloquentWebhookRepository implements WebhookRepository
{
    public function nextIdentity(): string
    {
        return (string) Str::orderedUuid();
    }

    public function nextDeliveryIdentity(): string
    {
        return (string) Str::orderedUuid();
    }

    public function findById(string $id): ?Webhook
    {
        $m = WebhookModel::query()->find($id);

        return $m !== null ? $this->toDomain($m) : null;
    }

    public function forApplication(string $applicationId): array
    {
        return array_map(
            fn (WebhookModel $m): Webhook => $this->toDomain($m),
            WebhookModel::query()->where('application_id', $applicationId)->orderByDesc('created_at')->get()->all(),
        );
    }

    public function subscribedTo(string $eventName): array
    {
        $out = [];
        foreach (WebhookModel::query()->where('status', WebhookStatus::Active->value)->get()->all() as $m) {
            $webhook = $this->toDomain($m);
            if ($webhook->subscribesTo($eventName)) {
                $out[] = $webhook;
            }
        }

        return $out;
    }

    public function save(Webhook $w): void
    {
        $m = WebhookModel::query()->find($w->id()) ?? new WebhookModel();
        $m->id = $w->id();
        $m->application_id = $w->applicationId();
        $m->url = $w->url();
        $m->events = $w->events();
        $m->secret = $w->secret();
        $m->status = $w->status()->value;
        $m->created_at = $w->createdAt();
        $m->updated_at = $w->updatedAt();
        $m->save();
    }

    public function findDelivery(string $id): ?WebhookDelivery
    {
        $m = WebhookDeliveryModel::query()->find($id);

        return $m !== null ? $this->deliveryToDomain($m) : null;
    }

    public function deliveryExists(string $webhookId, string $eventId): bool
    {
        return WebhookDeliveryModel::query()
            ->where('webhook_id', $webhookId)->where('event_id', $eventId)->exists();
    }

    public function dueDeliveries(DateTimeImmutable $now, int $limit): array
    {
        return array_map(
            fn (WebhookDeliveryModel $m): WebhookDelivery => $this->deliveryToDomain($m),
            WebhookDeliveryModel::query()
                ->whereIn('status', [DeliveryStatus::Pending->value, DeliveryStatus::Retrying->value])
                ->whereNotNull('next_attempt_at')
                ->where('next_attempt_at', '<=', $now->format('Y-m-d H:i:s'))
                ->orderBy('next_attempt_at')
                ->limit($limit)
                ->get()
                ->all(),
        );
    }

    public function deliveriesForWebhook(string $webhookId, int $limit): array
    {
        return array_map(
            fn (WebhookDeliveryModel $m): WebhookDelivery => $this->deliveryToDomain($m),
            WebhookDeliveryModel::query()->where('webhook_id', $webhookId)
                ->orderByDesc('created_at')->limit($limit)->get()->all(),
        );
    }

    public function saveDelivery(WebhookDelivery $d): void
    {
        $m = WebhookDeliveryModel::query()->find($d->id()) ?? new WebhookDeliveryModel();
        $m->id = $d->id();
        $m->webhook_id = $d->webhookId();
        $m->event_id = $d->eventId();
        $m->event_name = $d->eventName();
        $m->payload = $d->payload();
        $m->status = $d->status()->value;
        $m->attempts = $d->attempts();
        $m->last_response_code = $d->lastResponseCode();
        $m->last_error = $d->lastError();
        $m->created_at = $d->createdAt();
        $m->next_attempt_at = $d->nextAttemptAt();
        $m->delivered_at = $d->deliveredAt();
        $m->save();
    }

    private function toDomain(WebhookModel $m): Webhook
    {
        return Webhook::reconstitute(
            $m->id,
            $m->application_id,
            $m->url,
            array_values(array_map('strval', $m->events ?? [])),
            (string) $m->secret,
            WebhookStatus::from($m->status),
            DateTimeImmutable::createFromInterface($m->created_at),
            DateTimeImmutable::createFromInterface($m->updated_at),
        );
    }

    private function deliveryToDomain(WebhookDeliveryModel $m): WebhookDelivery
    {
        return WebhookDelivery::reconstitute(
            $m->id,
            $m->webhook_id,
            $m->event_id,
            $m->event_name,
            (string) $m->payload,
            DeliveryStatus::from($m->status),
            (int) $m->attempts,
            $m->last_response_code !== null ? (int) $m->last_response_code : null,
            $m->last_error,
            DateTimeImmutable::createFromInterface($m->created_at),
            $m->next_attempt_at !== null ? DateTimeImmutable::createFromInterface($m->next_attempt_at) : null,
            $m->delivered_at !== null ? DateTimeImmutable::createFromInterface($m->delivered_at) : null,
        );
    }
}
