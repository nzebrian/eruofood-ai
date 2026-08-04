<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Application\Service;

use EruoFood\PublicApi\Domain\ApiKey\ApiKey;
use EruoFood\PublicApi\Domain\Application\Application;

/** The resolved identity behind an authenticated public-API request. */
final readonly class AuthenticatedClient
{
    public function __construct(
        public Application $application,
        public ApiKey $apiKey,
    ) {
    }
}
