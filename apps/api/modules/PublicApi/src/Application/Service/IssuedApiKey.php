<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Application\Service;

use EruoFood\PublicApi\Domain\ApiKey\ApiKey;

/**
 * The result of issuing a key: the persisted {@see ApiKey} plus the ONE-TIME
 * plaintext. The plaintext is returned to the caller once and never stored.
 */
final readonly class IssuedApiKey
{
    public function __construct(
        public ApiKey $key,
        public string $plaintext,
    ) {
    }
}
