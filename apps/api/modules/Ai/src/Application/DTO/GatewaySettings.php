<?php

declare(strict_types=1);

namespace EruoFood\Ai\Application\DTO;

/**
 * Runtime knobs for the {@see \EruoFood\Ai\Application\Service\AiGateway},
 * assembled from config/ai.php by the service provider. Passing them as a value
 * object keeps the gateway free of the config facade and trivially testable.
 */
final readonly class GatewaySettings
{
    public function __construct(
        public bool $cacheEnabled,
        public int $cacheTtlSeconds,
        public int $retryAttempts,
        public int $retryBaseDelayMs,
    ) {
    }
}
