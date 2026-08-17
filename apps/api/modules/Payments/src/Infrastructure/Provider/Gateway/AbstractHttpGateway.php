<?php

declare(strict_types=1);

namespace EruoFood\Payments\Infrastructure\Provider\Gateway;

use EruoFood\Payments\Application\DTO\GatewayCharge;
use EruoFood\Payments\Application\DTO\GatewayResult;
use EruoFood\Payments\Application\Port\PaymentGateway;
use EruoFood\Payments\Application\Port\PayoutGateway;
use EruoFood\Payments\Domain\Enum\GatewayOutcome;
use EruoFood\Payments\Domain\Exception\ProviderError;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Throwable;

/**
 * Shared behaviour for HTTP-backed provider adapters: an authenticated client,
 * a hex HMAC-SHA512/256 signature check for webhooks, and safe result building.
 * Concrete providers (Paystack, Flutterwave, Moniepoint, Stripe, PayPal) map
 * their specific request/response shapes onto {@see PaymentGateway}.
 *
 * @phpstan-type Config array<string, mixed>
 */
abstract class AbstractHttpGateway implements PaymentGateway, PayoutGateway
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        protected readonly HttpFactory $http,
        protected readonly array $config,
    ) {
    }

    protected function client(): PendingRequest
    {
        return $this->http
            ->baseUrl((string) ($this->config['base_url'] ?? ''))
            ->acceptJson()
            ->timeout(20)
            ->withToken((string) ($this->config['secret_key'] ?? ($this->config['secret'] ?? '')));
    }

    /** Constant-time HMAC hex check used by most Nigerian providers. */
    protected function hmacMatches(string $rawBody, string $signature, string $algo = 'sha512'): bool
    {
        $secret = (string) ($this->config['webhook_secret'] ?? ($this->config['secret_key'] ?? ''));
        if ($secret === '') {
            return false;
        }

        return hash_equals(hash_hmac($algo, $rawBody, $secret), $signature);
    }

    protected function fail(string $message): ProviderError
    {
        return new ProviderError($message);
    }

    /**
     * @param array<string, mixed> $raw
     */
    protected function result(bool $success, string $reference, string $status, ?string $url = null, array $raw = []): GatewayResult
    {
        return new GatewayResult($success, $reference, $status, $url, null, $raw);
    }

    /**
     * Ask the provider what became of a transfer.
     *
     * Driven by a configured `transfer_status_path` containing `{reference}`
     * rather than a hardcoded URL per adapter, because inventing a provider's
     * endpoint from memory would produce a reconciler that confidently reports
     * "not found" for every transfer that exists.
     *
     * Three things here are deliberate and each of them protects a merchant
     * from being paid twice:
     *
     * 1. **No configured path → `Unknown`, not `Failed`.** An unconfigured
     *    provider means we cannot ask, which is not the same as an answer of
     *    "it did not happen". `Unknown` never resolves a reconciliation case,
     *    so an unconfigured provider escalates to a human instead of unlocking
     *    a retry.
     * 2. **Any transport failure → `Unknown`.** Including a 404: a provider
     *    that is having an outage returns 404s that mean nothing.
     * 3. **`Failed` only on an explicit failure status in the body.** The one
     *    outcome that makes a second transfer legal has to be something the
     *    provider actually said.
     */
    public function fetchTransferStatus(string $providerReference): GatewayResult
    {
        $path = (string) ($this->config['transfer_status_path'] ?? '');
        if (trim($path) === '') {
            return GatewayResult::unknown(
                $providerReference,
                'No transfer_status_path configured for this provider; status cannot be established.',
            );
        }

        try {
            $response = $this->client()->get(str_replace('{reference}', rawurlencode($providerReference), $path));

            if ($response->failed()) {
                return GatewayResult::unknown($providerReference, 'Provider status query returned HTTP '.$response->status());
            }

            /** @var array<string, mixed> $body */
            $body = (array) $response->json();
        } catch (Throwable $e) {
            // The class of failure the boolean flag used to swallow. It is
            // reported as unknown on purpose, and the message is the exception
            // class rather than its text — provider messages have been known to
            // echo request bodies back.
            return GatewayResult::unknown($providerReference, 'Provider status query failed: '.$e::class);
        }

        return GatewayResult::of(
            $this->transferOutcomeFrom($body),
            $providerReference,
            null,
            $body,
        );
    }

    /**
     * Map a provider status body onto an outcome.
     *
     * Overridable: providers spell these differently, and an adapter that knows
     * its own vocabulary should say so rather than rely on this list.
     *
     * @param array<string, mixed> $body
     */
    protected function transferOutcomeFrom(array $body): GatewayOutcome
    {
        /** @var array<string, mixed> $data */
        $data = is_array($body['data'] ?? null) ? $body['data'] : $body;

        $status = strtolower(trim((string) ($data['status'] ?? '')));

        return match ($status) {
            'success', 'successful', 'succeeded', 'completed', 'paid' => GatewayOutcome::Succeeded,
            'pending', 'processing', 'queued', 'new' => GatewayOutcome::Processing,
            'failed', 'reversed', 'declined', 'rejected', 'abandoned' => GatewayOutcome::Failed,
            // Includes the empty string: a 200 with a body we cannot read tells
            // us nothing, and must not read as a decline.
            default => GatewayOutcome::Unknown,
        };
    }

    abstract public function initialize(GatewayCharge $charge): GatewayResult;
}
