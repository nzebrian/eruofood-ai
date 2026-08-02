<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Interface\Http\Middleware;

use Closure;
use EruoFood\PublicApi\Application\Service\RateLimitService;
use EruoFood\Shared\Domain\EventBus;
use EruoFood\PublicApi\Domain\Event\RateLimitExceeded;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-client rate limiting with burst protection. Adds the standard
 * `X-RateLimit-Limit/Remaining/Reset` headers to every response and returns a
 * 429 with `Retry-After` when the client exceeds its allowance.
 */
final readonly class ApiRateLimit
{
    public function __construct(
        private RateLimitService $rateLimits,
        private EventBus $events,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $applicationId = (string) $request->attributes->get('publicapi_application_id', 'anonymous');
        $routeName = $request->route()?->getName() ?? $request->path();

        $result = $this->rateLimits->check($applicationId, (string) $routeName);

        if (! $result->allowed) {
            $this->events->publish(new RateLimitExceeded($applicationId, (string) $routeName));
            $retryAfter = max(1, $result->resetAtEpoch - time());

            return new JsonResponse([
                'error' => ['code' => 'PUBLICAPI_RATE_LIMITED', 'message' => 'Rate limit exceeded.'],
            ], 429, $this->headers($result->limit, 0, $result->resetAtEpoch) + ['Retry-After' => (string) $retryAfter]);
        }

        $response = $next($request);
        foreach ($this->headers($result->limit, $result->remaining, $result->resetAtEpoch) as $k => $v) {
            $response->headers->set($k, $v);
        }

        return $response;
    }

    /**
     * @return array<string, string>
     */
    private function headers(int $limit, int $remaining, int $reset): array
    {
        return [
            'X-RateLimit-Limit' => (string) $limit,
            'X-RateLimit-Remaining' => (string) $remaining,
            'X-RateLimit-Reset' => (string) $reset,
        ];
    }
}
