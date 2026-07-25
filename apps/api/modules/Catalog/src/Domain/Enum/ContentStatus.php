<?php

declare(strict_types=1);

namespace EruoFood\Catalog\Domain\Enum;

/** Publication lifecycle for foods and recipes. */
enum ContentStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';

    public function isPublished(): bool
    {
        return $this === self::Published;
    }
}
