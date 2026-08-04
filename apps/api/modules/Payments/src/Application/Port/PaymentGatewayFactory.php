<?php

declare(strict_types=1);

namespace EruoFood\Payments\Application\Port;

use EruoFood\Payments\Domain\Enum\PaymentProvider;

/**
 * Resolves a {@see PaymentGateway} adapter by provider (the **Provider
 * Factory**). Falls back through the configured chain when a provider is
 * disabled/unavailable.
 */
interface PaymentGatewayFactory
{
    public function for(PaymentProvider $provider): PaymentGateway;

    public function default(): PaymentGateway;

    /** @return list<PaymentProvider> the enabled providers, default first */
    public function available(): array;
}
