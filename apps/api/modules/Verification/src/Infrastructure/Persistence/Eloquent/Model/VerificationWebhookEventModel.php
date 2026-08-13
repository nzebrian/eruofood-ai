<?php

declare(strict_types=1);

namespace EruoFood\Verification\Infrastructure\Persistence\Eloquent\Model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $provider
 * @property string $provider_event_id
 * @property string $signature_scheme
 * @property DateTimeInterface $received_at
 */
final class VerificationWebhookEventModel extends Model
{
    protected $table = 'verification_webhook_events';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
        ];
    }
}
