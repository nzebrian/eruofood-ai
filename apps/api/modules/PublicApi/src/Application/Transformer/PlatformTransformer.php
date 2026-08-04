<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Application\Transformer;

use EruoFood\PublicApi\Domain\ApiKey\ApiKey;
use EruoFood\PublicApi\Domain\Application\Application;
use EruoFood\PublicApi\Domain\Developer\Developer;
use EruoFood\PublicApi\Domain\Webhook\Webhook;
use EruoFood\PublicApi\Domain\Webhook\WebhookDelivery;

/** Transforms developer-platform entities into portal JSON. Secrets are never emitted (except the one-time plaintext at issue). */
final readonly class PlatformTransformer
{
    /**
     * @return array<string, mixed>
     */
    public function developer(Developer $d): array
    {
        return [
            'id' => $d->id(),
            'name' => $d->name(),
            'email' => $d->email(),
            'created_at' => $d->createdAt()->format(DATE_ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function application(Application $a): array
    {
        return [
            'id' => $a->id(),
            'name' => $a->name(),
            'description' => $a->description(),
            'scopes' => $a->scopes()->toArray(),
            'status' => $a->status()->value,
            'created_at' => $a->createdAt()->format(DATE_ATOM),
            'updated_at' => $a->updatedAt()->format(DATE_ATOM),
        ];
    }

    /**
     * The safe representation of a key — prefix only, never the secret or hash.
     *
     * @return array<string, mixed>
     */
    public function apiKey(ApiKey $k): array
    {
        return [
            'id' => $k->id(),
            'name' => $k->name(),
            'prefix' => $k->prefix(),
            'scopes' => $k->scopes()->toArray(),
            'status' => $k->status()->value,
            'expires_at' => $k->expiresAt()?->format(DATE_ATOM),
            'last_used_at' => $k->lastUsedAt()?->format(DATE_ATOM),
            'created_at' => $k->createdAt()->format(DATE_ATOM),
            'revoked_at' => $k->revokedAt()?->format(DATE_ATOM),
        ];
    }

    /**
     * The one-time issue response — includes the plaintext key ONCE.
     *
     * @return array<string, mixed>
     */
    public function issuedKey(ApiKey $k, string $plaintext): array
    {
        return $this->apiKey($k) + ['key' => $plaintext, 'notice' => 'Store this key now — it will not be shown again.'];
    }

    /**
     * @return array<string, mixed>
     */
    public function webhook(Webhook $w, bool $includeSecret = false): array
    {
        $out = [
            'id' => $w->id(),
            'url' => $w->url(),
            'events' => $w->events(),
            'status' => $w->status()->value,
            'created_at' => $w->createdAt()->format(DATE_ATOM),
            'updated_at' => $w->updatedAt()->format(DATE_ATOM),
        ];
        if ($includeSecret) {
            $out['secret'] = $w->secret(); // shown once on create/rotate
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function delivery(WebhookDelivery $d): array
    {
        return [
            'id' => $d->id(),
            'event_id' => $d->eventId(),
            'event' => $d->eventName(),
            'status' => $d->status()->value,
            'attempts' => $d->attempts(),
            'last_response_code' => $d->lastResponseCode(),
            'last_error' => $d->lastError(),
            'created_at' => $d->createdAt()->format(DATE_ATOM),
            'next_attempt_at' => $d->nextAttemptAt()?->format(DATE_ATOM),
            'delivered_at' => $d->deliveredAt()?->format(DATE_ATOM),
        ];
    }
}
