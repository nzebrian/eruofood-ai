<?php

declare(strict_types=1);

namespace EruoFood\Verification\Infrastructure\Persistence\Eloquent;

use EruoFood\Verification\Domain\Enum\ProviderName;
use EruoFood\Verification\Domain\Webhook\WebhookEventRepository;
use EruoFood\Verification\Infrastructure\Persistence\Eloquent\Model\VerificationWebhookEventModel;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EloquentWebhookEventRepository implements WebhookEventRepository
{
    public function claim(ProviderName $provider, string $providerEventId, string $signatureScheme): bool
    {
        // Wrapped so a losing race does not poison the caller's transaction. On
        // PostgreSQL a constraint violation aborts the *entire* enclosing
        // transaction — and this is deliberately called inside one — so without
        // the wrapper the duplicate-delivery path would fail with "current
        // transaction is aborted" instead of returning false. Nested, the
        // wrapper is a SAVEPOINT. This is the same fix M23 applied to the
        // payments webhook claim.
        try {
            DB::transaction(function () use ($provider, $providerEventId, $signatureScheme): void {
                $row = new VerificationWebhookEventModel();
                $row->id = (string) Str::orderedUuid();
                $row->provider = $provider->value;
                $row->provider_event_id = $providerEventId;
                $row->signature_scheme = $signatureScheme;
                $row->received_at = Carbon::now();
                $row->save();
            });

            return true;
        } catch (UniqueConstraintViolationException) {
            // Another delivery of this same event got here first.
            return false;
        }
    }
}
