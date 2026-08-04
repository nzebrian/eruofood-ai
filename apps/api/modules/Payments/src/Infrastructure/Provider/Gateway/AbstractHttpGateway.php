<?php

declare(strict_types=1);

namespace EruoFood\Payments\Infrastructure\Provider\Gateway;

use EruoFood\Payments\Application\DTO\GatewayCharge;
use EruoFood\Payments\Application\DTO\GatewayResult;
use EruoFood\Payments\Application\Port\PaymentGateway;
use EruoFood\Payments\Domain\Exception\ProviderError;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;

/**
 * Shared behaviour for HTTP-backed provider adapters: an authenticated client,
 * a hex HMAC-SHA512/256 signature check for webhooks, and safe result building.
 * Concrete providers (Paystack, Flutterwave, Moniepoint, Stripe, PayPal) map
 * their specific request/response shapes onto {@see PaymentGateway}.
 *
 * @phpstan-type Config array<string, mixed>
 */
abstract class AbstractHttpGateway implements PaymentGateway
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

    abstract public function initialize(GatewayCharge $charge): GatewayResult;
}
