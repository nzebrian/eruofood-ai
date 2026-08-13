<?php

declare(strict_types=1);

namespace EruoFood\Verification\Application\DTO;

use EruoFood\Verification\Domain\Enum\ProviderName;
use EruoFood\Verification\Domain\Enum\VerificationStatus;

/**
 * A provider session the subject can go and complete.
 *
 * `hostedUrl` is null for providers that decide server-side with no user
 * interaction — a business registry lookup, for instance — which is why the
 * caller must treat it as optional rather than assuming a redirect.
 */
final readonly class VerificationSession
{
    public function __construct(
        public ProviderName $provider,
        public string $providerReference,
        public VerificationStatus $status,
        public ?string $hostedUrl = null,
    ) {
    }
}
