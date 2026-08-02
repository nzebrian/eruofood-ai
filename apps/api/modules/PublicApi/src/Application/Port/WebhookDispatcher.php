<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Application\Port;

/**
 * Performs the outbound HTTP POST of a signed webhook payload. Abstracted so the
 * delivery logic (signing, retries, logging) stays testable without real HTTP.
 *
 * @phpstan-param array<string, string> $headers
 */
interface WebhookDispatcher
{
    /**
     * @param array<string, string> $headers
     */
    public function post(string $url, string $payload, array $headers, int $timeoutSeconds): DispatchResult;
}
