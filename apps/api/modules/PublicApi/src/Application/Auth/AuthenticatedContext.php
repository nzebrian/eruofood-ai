<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Application\Auth;

use EruoFood\PublicApi\Domain\ValueObject\ScopeSet;

/**
 * The mechanism-independent result of authenticating a public-API request. Both
 * the API-key path and the OAuth2 access-token path resolve to this same shape,
 * so everything downstream — scope enforcement and BOLA/object-level
 * authorization keyed on the subject user — is identical regardless of how the
 * caller authenticated. This is the seam that lets auth mechanisms be added or
 * swapped without touching domain logic.
 */
final readonly class AuthenticatedContext
{
    public function __construct(
        public string $applicationId,
        public string $developerId,
        public ScopeSet $scopes,
        public ?string $subjectUserId,
        public string $authVia,
        public ?string $credentialId = null,
    ) {
    }
}
