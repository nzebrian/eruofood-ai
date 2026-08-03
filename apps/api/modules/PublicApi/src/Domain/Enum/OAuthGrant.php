<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Domain\Enum;

/**
 * The OAuth2 grant types the platform supports. Authorization Code (always with
 * PKCE for public clients), Client Credentials (machine-to-machine, no user),
 * and Refresh Token (renew an access token without re-consent).
 */
enum OAuthGrant: string
{
    case AuthorizationCode = 'authorization_code';
    case ClientCredentials = 'client_credentials';
    case RefreshToken = 'refresh_token';
}
