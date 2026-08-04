<?php

declare(strict_types=1);

namespace EruoFood\Admin\Infrastructure\Persistence\Eloquent\Model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $question
 * @property string $answer
 * @property string $category
 * @property int $sort_order
 * @property bool $published
 * @property DateTimeInterface $created_at
 * @property DateTimeInterface $updated_at
 */
final class FaqModel extends Model
{
    protected $table = 'admin_faqs';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'published' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
