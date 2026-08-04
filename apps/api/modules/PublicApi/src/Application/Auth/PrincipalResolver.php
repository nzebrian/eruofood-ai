<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Application\Auth;

/**
 * Resolves a presented credential to an {@see AuthenticatedContext}, or null if
 * it cannot handle / does not recognise the credential. Each authentication
 * mechanism (API key, OAuth2 bearer token, …) is one resolver; the gateway tries
 * them in order. Adding a new mechanism is adding a resolver — no gateway,
 * scope, or domain code changes — which keeps auth replaceable and isolated.
 */
interface PrincipalResolver
{
    /**
     * @param string $scheme the transport the credential arrived on: 'bearer' or 'api_key_header'
     * @param string $credential the raw presented secret
     */
    public function resolve(string $scheme, string $credential): ?AuthenticatedContext;
}
