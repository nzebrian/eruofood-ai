<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Domain\Enum;

/**
 * A product's moderation/publication lifecycle. Only Published products appear
 * in the public catalogue; the admin approval queue moves Pending → Published
 * or Rejected.
 */
enum ProductStatus: string
{
    case Draft = 'draft';         // vendor is still editing
    case Pending = 'pending';     // submitted, awaiting admin approval
    case Published = 'published'; // live in the catalogue
    case Rejected = 'rejected';   // admin declined

    public function isPublic(): bool
    {
        return $this === self::Published;
    }
}
