<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $key
 * @property string $channel
 * @property string $locale
 * @property string $subject
 * @property string $body
 */
final class NotificationTemplateModel extends Model
{
    protected $table = 'notifications_templates';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [];
    }
}
