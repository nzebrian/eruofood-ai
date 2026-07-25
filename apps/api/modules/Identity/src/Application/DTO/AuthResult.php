<?php

declare(strict_types=1);

namespace EruoFood\Identity\Application\DTO;

/**
 * Outcome of an authentication attempt: either fully authenticated (tokens +
 * profile) or a two-factor challenge that must be completed to obtain tokens.
 */
final readonly class AuthResult
{
    private function __construct(
        public bool $twoFactorRequired,
        public ?UserProfileView $user,
        public ?AuthTokens $tokens,
        public ?string $challengeToken,
    ) {
    }

    public static function authenticated(UserProfileView $user, AuthTokens $tokens): self
    {
        return new self(false, $user, $tokens, null);
    }

    public static function twoFactorChallenge(string $challengeToken): self
    {
        return new self(true, null, null, $challengeToken);
    }
}
