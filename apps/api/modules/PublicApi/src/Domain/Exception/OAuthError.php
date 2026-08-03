<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Domain\Exception;

use EruoFood\Shared\Domain\Exception\DomainException;

/**
 * An OAuth2 protocol error. Carries the standard RFC 6749 `error` identifier
 * (invalid_client, invalid_grant, invalid_scope, …) so the token endpoint can
 * render a spec-compliant error body without leaking why authentication failed.
 */
final class OAuthError extends DomainException
{
    private function __construct(private readonly string $oauthError, string $message)
    {
        parent::__construct($message);
    }

    public static function invalidRequest(string $message = 'The request is missing a required parameter or is malformed.'): self
    {
        return new self('invalid_request', $message);
    }

    public static function invalidClient(string $message = 'Client authentication failed.'): self
    {
        return new self('invalid_client', $message);
    }

    public static function invalidGrant(string $message = 'The authorization grant is invalid, expired or revoked.'): self
    {
        return new self('invalid_grant', $message);
    }

    public static function unsupportedGrantType(string $message = 'The grant type is not supported.'): self
    {
        return new self('unsupported_grant_type', $message);
    }

    public static function invalidScope(string $message = 'The requested scope is invalid or exceeds the grant.'): self
    {
        return new self('invalid_scope', $message);
    }

    public function oauthError(): string
    {
        return $this->oauthError;
    }

    public function errorCode(): string
    {
        return 'PUBLICAPI_OAUTH_'.strtoupper($this->oauthError);
    }
}
