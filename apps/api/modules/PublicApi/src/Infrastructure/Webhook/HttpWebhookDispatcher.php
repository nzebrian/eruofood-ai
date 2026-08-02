<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Infrastructure\Webhook;

use EruoFood\PublicApi\Application\Port\DispatchResult;
use EruoFood\PublicApi\Application\Port\WebhookDispatcher;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Delivers webhook payloads over HTTP with a bounded timeout. Any non-2xx or
 * transport error is reported as a failure so the delivery is retried by the
 * backoff policy — this adapter makes no retry decisions itself.
 */
final class HttpWebhookDispatcher implements WebhookDispatcher
{
    public function post(string $url, string $payload, array $headers, int $timeoutSeconds): DispatchResult
    {
        try {
            $response = Http::withHeaders($headers)
                ->timeout(max(1, $timeoutSeconds))
                ->withBody($payload, 'application/json')
                ->post($url);

            return new DispatchResult(
                $response->successful(),
                $response->status(),
                $response->successful() ? null : 'Non-2xx response',
            );
        } catch (Throwable $e) {
            return new DispatchResult(false, null, $e->getMessage());
        }
    }
}
