<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Domain\Enum;

/** Lifecycle of an API key. Expiry is derived from the key's expires_at, not a stored state. */
enum ApiKeyStatus: string
{
    case Active = 'active';
    case Revoked = 'revoked';
}
