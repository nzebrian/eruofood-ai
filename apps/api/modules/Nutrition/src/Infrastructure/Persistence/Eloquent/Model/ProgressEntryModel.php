<?php

declare(strict_types=1);

namespace EruoFood\Nutrition\Infrastructure\Persistence\Eloquent\Model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $user_id
 * @property string $entry_date
 * @property float $weight_kg
 * @property string|null $note
 * @property DateTimeInterface $created_at
 */
final class ProgressEntryModel extends Model
{
    protected $table = 'nutrition_progress_entries';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['weight_kg' => 'float'];
    }
}
