<?php

declare(strict_types=1);

namespace EruoFood\Ai\Application\Port;

/**
 * Enforces per-user AI request quotas over a rolling window. Keeps expensive
 * generation from being abused and protects the platform's provider spend.
 */
interface AiRateLimiter
{
    /**
     * Register an attempt for the user and fail if the quota is exceeded.
     *
     * @throws \EruoFood\Ai\Domain\Exception\RateLimitExceeded
     */
    public function hit(string $userId): void;
}
