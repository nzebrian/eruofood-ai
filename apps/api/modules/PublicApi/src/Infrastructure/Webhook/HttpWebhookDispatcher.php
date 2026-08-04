<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Infrastructure\Webhook;

use EruoFood\PublicApi\Application\Port\DispatchResult;
use EruoFood\PublicApi\Application\Port\WebhookDispatcher;
use EruoFood\PublicApi\Application\Port\WebhookUrlGuard;
use EruoFood\PublicApi\Domain\Exception\WebhookDestinationRejected;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Delivers webhook payloads over HTTP with a bounded timeout and SSRF-hardened
 * egress. Before every request it re-validates the destination through the
 * {@see WebhookUrlGuard} (closing the DNS-rebinding window that a registration-time
 * check alone leaves open), refuses to follow redirects (so a 30x cannot bounce
 * the request to an internal host), caps the connect time, and truncates the
 * response body it reads back. Any non-2xx, rejected destination, or transport
 * error is reported as a failure so the delivery is retried by the backoff
 * policy — this adapter makes no retry decisions itself.
 */
final readonly class HttpWebhookDispatcher implements WebhookDispatcher
{
    public function __construct(
        private WebhookUrlGuard $guard,
        private int $connectTimeoutSeconds = 5,
        private int $maxResponseBytes = 65536,
    ) {
    }

    public function post(string $url, string $payload, array $headers, int $timeoutSeconds): DispatchResult
    {
        try {
            // Re-check the destination at send time — DNS may have changed since
            // the endpoint was registered (rebinding), and this is the last gate.
            $this->guard->assertAllowed($url);
        } catch (WebhookDestinationRejected $e) {
            return new DispatchResult(false, null, $e->getMessage());
        }

        try {
            $response = Http::withHeaders($headers)
                ->timeout(max(1, $timeoutSeconds))
                ->connectTimeout(max(1, $this->connectTimeoutSeconds))
                ->withoutRedirecting()
                ->withOptions([
                    'stream' => false,
                    'read_timeout' => max(1, $timeoutSeconds),
                    // Cap the response body we are willing to read from an endpoint.
                    'curl' => [CURLOPT_MAXFILESIZE => $this->maxResponseBytes],
                ])
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
