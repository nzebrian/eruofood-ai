<?php

declare(strict_types=1);

namespace EruoFood\Ai\Tests\Support;

use EruoFood\Ai\Application\Port\AiProvider;
use EruoFood\Ai\Application\Port\ProviderRegistry;
use EruoFood\Ai\Domain\Enum\AiProviderName;
use EruoFood\Ai\Domain\Exception\ProviderUnavailable;

/** A registry over an explicit, ordered list of providers. */
final class ListProviderRegistry implements ProviderRegistry
{
    /** @param list<AiProvider> $providers */
    public function __construct(private readonly array $providers)
    {
    }

    public function default(): AiProvider
    {
        return $this->providers[0] ?? throw ProviderUnavailable::allExhausted();
    }

    public function get(AiProviderName $name): AiProvider
    {
        foreach ($this->providers as $provider) {
            if ($provider->name() === $name) {
                return $provider;
            }
        }

        throw ProviderUnavailable::named($name->value);
    }

    public function resolutionChain(): array
    {
        return $this->providers;
    }
}
