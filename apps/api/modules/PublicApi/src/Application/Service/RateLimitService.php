<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Application\Service;

use EruoFood\PublicApi\Application\Port\RateLimiter;
use EruoFood\PublicApi\Domain\ValueObject\RateLimitResult;

/**
 * Per-client rate limiting with burst protection. Two fixed windows are checked:
 * a per-minute allowance (optionally tightened per endpoint) and a short burst
 * window. A request passes only if both allow it; the per-minute figures drive
 * the standard `X-RateLimit-*` headers.
 */
final readonly class RateLimitService
{
    /**
     * @param array<string, int> $endpointOverrides route name => per-minute cap
     */
    public function __construct(
        private RateLimiter $limiter,
        private int $perMinute,
        private int $burst,
        private array $endpointOverrides,
    ) {
    }

    public function check(string $applicationId, string $routeName): RateLimitResult
    {
        $perMinute = $this->endpointOverrides[$routeName] ?? $this->perMinute;

        $minute = $this->limiter->hit(sprintf('publicapi:rl:%s:min', $applicationId), $perMinute, 60);
        $burst = $this->limiter->hit(sprintf('publicapi:rl:%s:burst', $applicationId), $this->burst, 10);

        $allowed = $minute->allowed && $burst->allowed;

        return new RateLimitResult(
            $allowed,
            $minute->limit,
            min($minute->remaining, $burst->remaining),
            $minute->resetAtEpoch,
        );
    }
}
