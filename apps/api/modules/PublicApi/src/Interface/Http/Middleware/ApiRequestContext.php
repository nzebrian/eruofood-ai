<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Interface\Http\Middleware;

use Closure;
use EruoFood\PublicApi\Domain\Event\ApiRequestServed;
use EruoFood\Shared\Domain\EventBus;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * The outermost public-API middleware. It stamps a request id, records the
 * request version, and — after the response — publishes {@see ApiRequestServed}
 * (consumed by Analytics for usage/latency/error-rate reporting) and applies
 * deprecation/sunset headers for deprecated versions. Response headers here let
 * clients correlate requests and detect version end-of-life.
 */
final readonly class ApiRequestContext
{
    /**
     * @param array<string, array{sunset?: string}> $deprecated
     */
    public function __construct(
        private EventBus $events,
        private string $version,
        private array $deprecated,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $requestId = (string) Str::uuid();
        $request->attributes->set('publicapi_request_id', $requestId);
        $startedAt = microtime(true);

        $response = $next($request);

        $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);
        $response->headers->set('X-Request-Id', $requestId);
        $response->headers->set('X-Api-Version', $this->version);

        if (isset($this->deprecated[$this->version])) {
            $response->headers->set('Deprecation', 'true');
            $sunset = $this->deprecated[$this->version]['sunset'] ?? null;
            if ($sunset !== null) {
                $response->headers->set('Sunset', (string) $sunset);
            }
        }

        $applicationId = $request->attributes->get('publicapi_application_id');
        if (is_string($applicationId)) {
            $this->events->publish(new ApiRequestServed(
                $applicationId,
                (string) ($request->route()?->getName() ?? $request->path()),
                $request->getMethod(),
                $response->getStatusCode(),
                $latencyMs,
                $this->version,
            ));
        }

        return $response;
    }
}
