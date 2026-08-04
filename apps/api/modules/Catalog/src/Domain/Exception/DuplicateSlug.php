<?php

declare(strict_types=1);

namespace EruoFood\Catalog\Domain\Exception;

use EruoFood\Shared\Domain\Exception\DomainException;

final class DuplicateSlug extends DomainException
{
    public function __construct(string $slug)
    {
        parent::__construct(sprintf('The slug "%s" is already in use.', $slug));
    }

    public function errorCode(): string
    {
        return 'DUPLICATE_SLUG';
    }
}
