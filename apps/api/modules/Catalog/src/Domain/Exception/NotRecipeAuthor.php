<?php

declare(strict_types=1);

namespace EruoFood\Catalog\Domain\Exception;

use EruoFood\Shared\Domain\Exception\DomainException;

/** Raised when a non-owner, non-admin tries to modify a recipe. */
final class NotRecipeAuthor extends DomainException
{
    public function __construct()
    {
        parent::__construct('You are not allowed to modify this recipe.');
    }

    public function errorCode(): string
    {
        return 'NOT_AUTHORIZED';
    }
}
