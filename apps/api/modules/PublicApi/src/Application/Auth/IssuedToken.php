<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Application\Auth;

use EruoFood\PublicApi\Domain\ValueObject\ScopeSet;

/**
 * The plaintext result of a successful token grant, ready to serialise into the
 * OAuth2 token response. The plaintext access/refresh values exist only here, in
 * transit to the client — persistence stores their hashes only.
 */
final readonly class IssuedToken
{
    public function __construct(
        public string $accessToken,
        public int $expiresIn,
        public ScopeSet $scopes,
        public ?string $refreshToken = null,
        public string $tokenType = 'Bearer',
    ) {
    }
}
