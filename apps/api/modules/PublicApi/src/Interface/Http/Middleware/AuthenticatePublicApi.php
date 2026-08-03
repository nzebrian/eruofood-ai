<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Interface\Http\Middleware;

use Closure;
use EruoFood\PublicApi\Application\Auth\AuthenticatedContext;
use EruoFood\PublicApi\Application\Auth\PrincipalResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a public-API request through an ordered chain of
 * {@see PrincipalResolver}s — API key first (Authorization: Bearer <key> or the
 * X-Api-Key header), then OAuth2 bearer access token. Both mechanisms resolve to
 * the same {@see AuthenticatedContext}, and on success the identical request
 * attributes are attached (application/developer ids, granted scopes, the BOLA
 * subject user, and how the caller authenticated). Downstream scope enforcement
 * and object-level authorization are therefore mechanism-agnostic; adding a new
 * auth mechanism means adding a resolver, not changing this gateway.
 */
final readonly class AuthenticatePublicApi
{
    /**
     * @param list<PrincipalResolver> $resolvers
     */
    public function __construct(private array $resolvers)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $context = $this->authenticate($request);
        if ($context === null) {
            return new JsonResponse([
                'error' => ['code' => 'PUBLICAPI_UNAUTHENTICATED', 'message' => 'A valid API key or access token is required.'],
            ], 401);
        }

        $request->attributes->set('publicapi_application_id', $context->applicationId);
        $request->attributes->set('publicapi_developer_id', $context->developerId);
        $request->attributes->set('publicapi_key_id', $context->credentialId);
        $request->attributes->set('publicapi_scopes', $context->scopes->toArray());
        $request->attributes->set('publicapi_subject_user_id', $context->subjectUserId);
        $request->attributes->set('publicapi_auth_via', $context->authVia);

        return $next($request);
    }

    private function authenticate(Request $request): ?AuthenticatedContext
    {
        [$scheme, $credential] = $this->presentedCredential($request);
        if ($credential === null) {
            return null;
        }

        foreach ($this->resolvers as $resolver) {
            $context = $resolver->resolve($scheme, $credential);
            if ($context !== null) {
                return $context;
            }
        }

        return null;
    }

    /**
     * @return array{0:string, 1:?string} [scheme, credential]
     */
    private function presentedCredential(Request $request): array
    {
        $bearer = $request->bearerToken();
        if (is_string($bearer) && $bearer !== '') {
            return ['bearer', $bearer];
        }

        $apiKey = $request->header('X-Api-Key');
        if (is_string($apiKey) && $apiKey !== '') {
            return ['api_key_header', $apiKey];
        }

        return ['none', null];
    }
}
