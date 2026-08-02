<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Interface\Http\Middleware;

use Closure;
use EruoFood\PublicApi\Application\Service\QuotaService;
use EruoFood\PublicApi\Domain\Event\QuotaExceeded;
use EruoFood\Shared\Domain\EventBus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces daily/monthly request quotas per client and surfaces usage via
 * `X-Quota-*` headers. Returns 429 when a period is exhausted.
 */
final readonly class ApiQuota
{
    public function __construct(
        private QuotaService $quotas,
        private EventBus $events,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $applicationId = (string) $request->attributes->get('publicapi_application_id', 'anonymous');
        $usage = $this->quotas->consume($applicationId);

        $headers = [
            'X-Quota-Daily-Limit' => (string) $usage['daily_limit'],
            'X-Quota-Daily-Used' => (string) $usage['daily_used'],
            'X-Quota-Monthly-Limit' => (string) $usage['monthly_limit'],
            'X-Quota-Monthly-Used' => (string) $usage['monthly_used'],
        ];

        if (! $usage['allowed']) {
            $this->events->publish(new QuotaExceeded($applicationId, (string) $usage['exceeded_period']));

            return new JsonResponse([
                'error' => ['code' => 'PUBLICAPI_QUOTA_EXCEEDED', 'message' => sprintf('%s quota exceeded.', ucfirst((string) $usage['exceeded_period']))],
            ], 429, $headers);
        }

        $response = $next($request);
        foreach ($headers as $k => $v) {
            $response->headers->set($k, $v);
        }

        return $response;
    }
}
