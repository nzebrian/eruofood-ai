<?php

declare(strict_types=1);

namespace EruoFood\Payments\Infrastructure\Provider;

use EruoFood\Payments\Application\Port\PaymentGateway;
use EruoFood\Payments\Application\Port\PaymentGatewayFactory;
use EruoFood\Payments\Domain\Enum\PaymentProvider;
use EruoFood\Payments\Domain\Exception\ProviderError;
use EruoFood\Payments\Infrastructure\Provider\Gateway\FlutterwaveGateway;
use EruoFood\Payments\Infrastructure\Provider\Gateway\MockGateway;
use EruoFood\Payments\Infrastructure\Provider\Gateway\MoniepointGateway;
use EruoFood\Payments\Infrastructure\Provider\Gateway\PaypalGateway;
use EruoFood\Payments\Infrastructure\Provider\Gateway\PaystackGateway;
use EruoFood\Payments\Infrastructure\Provider\Gateway\StripeGateway;
use EruoFood\Payments\Infrastructure\Provider\Gateway\WalletGateway;
use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * The **Provider Factory**: builds a {@see PaymentGateway} for a named provider
 * and knows the configured default + fallback chain. Concrete adapters are
 * constructed lazily from config so disabled providers cost nothing.
 */
final class GatewayFactory implements PaymentGatewayFactory
{
    /**
     * @param array<string, mixed> $config the `payments` config array
     */
    public function __construct(
        private readonly HttpFactory $http,
        private readonly array $config,
    ) {
    }

    public function for(PaymentProvider $provider): PaymentGateway
    {
        /** @var array<string, mixed> $providers */
        $providers = $this->config['providers'] ?? [];
        $conf = (array) ($providers[$provider->value] ?? []);

        return match ($provider) {
            PaymentProvider::Mock => new MockGateway(),
            PaymentProvider::Wallet => new WalletGateway(),
            PaymentProvider::Paystack => new PaystackGateway($this->http, $conf),
            PaymentProvider::Flutterwave => new FlutterwaveGateway($this->http, $conf),
            PaymentProvider::Moniepoint => new MoniepointGateway($this->http, $conf),
            PaymentProvider::Stripe => new StripeGateway($this->http, $conf),
            PaymentProvider::Paypal => new PaypalGateway($this->http, $conf),
        };
    }

    public function default(): PaymentGateway
    {
        $default = PaymentProvider::tryFrom((string) ($this->config['default'] ?? 'mock')) ?? PaymentProvider::Mock;
        if ($this->enabled($default)) {
            return $this->for($default);
        }
        foreach ($this->fallbackChain() as $provider) {
            if ($this->enabled($provider)) {
                return $this->for($provider);
            }
        }

        throw new ProviderError('No enabled payment provider is available.');
    }

    public function available(): array
    {
        $out = [];
        $default = PaymentProvider::tryFrom((string) ($this->config['default'] ?? 'mock'));
        if ($default !== null && $this->enabled($default)) {
            $out[] = $default;
        }
        foreach ($this->fallbackChain() as $provider) {
            if ($this->enabled($provider) && ! in_array($provider, $out, true)) {
                $out[] = $provider;
            }
        }

        return $out;
    }

    private function enabled(PaymentProvider $provider): bool
    {
        /** @var array<string, mixed> $providers */
        $providers = $this->config['providers'] ?? [];

        return (bool) (($providers[$provider->value]['enabled'] ?? false));
    }

    /** @return list<PaymentProvider> */
    private function fallbackChain(): array
    {
        $chain = [];
        foreach ((array) ($this->config['fallbacks'] ?? []) as $name) {
            $provider = PaymentProvider::tryFrom((string) $name);
            if ($provider !== null) {
                $chain[] = $provider;
            }
        }
        $chain[] = PaymentProvider::Mock; // always-available last resort

        return $chain;
    }
}
