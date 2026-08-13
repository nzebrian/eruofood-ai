<?php

declare(strict_types=1);

namespace EruoFood\Payments\Infrastructure\Persistence\Eloquent;

use EruoFood\Payments\Domain\Webhook\WebhookEventRepository;
use EruoFood\Payments\Infrastructure\Persistence\Eloquent\Model\WebhookEventModel;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EloquentWebhookEventRepository implements WebhookEventRepository
{
    public function seen(string $provider, string $eventId): bool
    {
        return WebhookEventModel::query()->where('provider', $provider)->where('event_id', $eventId)->exists();
    }

    public function claim(string $provider, string $eventId, string $type): bool
    {
        // The insert is wrapped so a losing race does not poison the caller's
        // transaction. On PostgreSQL a constraint violation aborts the whole
        // enclosing transaction — and this method is deliberately called inside
        // one — so without the wrapper the "duplicate delivery" path would fail
        // with "current transaction is aborted" instead of returning false.
        // Nested, the wrapper is a SAVEPOINT; standalone, a short transaction.
        try {
            DB::transaction(function () use ($provider, $eventId, $type): void {
                $this->record($provider, $eventId, $type);
            });

            return true;
        } catch (UniqueConstraintViolationException) {
            // Another delivery of this same event got here first.
            return false;
        }
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
