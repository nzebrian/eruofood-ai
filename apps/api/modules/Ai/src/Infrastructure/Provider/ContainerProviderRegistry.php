<?php

declare(strict_types=1);

namespace EruoFood\Ai\Infrastructure\Provider;

use EruoFood\Ai\Application\Port\AiProvider;
use EruoFood\Ai\Application\Port\ProviderRegistry;
use EruoFood\Ai\Domain\Enum\AiProviderName;
use EruoFood\Ai\Domain\Exception\ProviderUnavailable;

/**
 * Concrete {@see ProviderRegistry} assembled from config/ai.php.
 *
 * Holds the registered adapters and knows the configured default + fallback
 * order. The resolution chain the gateway walks is de-duplicated and filtered to
 * providers that are actually configured, so an unconfigured provider (e.g. one
 * missing its API key) is transparently skipped rather than failing the request.
 */
final readonly class ContainerProviderRegistry implements ProviderRegistry
{
    /**
     * @param array<string, AiProvider> $providers keyed by provider name value
     * @param list<string> $fallbackNames ordered fallback provider names
     */
    public function __construct(
        private array $providers,
        private string $defaultName,
        private array $fallbackNames,
    ) {
    }

    public function default(): AiProvider
    {
        return $this->providers[$this->defaultName]
            ?? throw ProviderUnavailable::named($this->defaultName);
    }

    public function get(AiProviderName $name): AiProvider
    {
        return $this->providers[$name->value]
            ?? throw ProviderUnavailable::named($name->value);
    }

    public function resolutionChain(): array
    {
        $ordered = array_values(array_unique([$this->defaultName, ...$this->fallbackNames]));

        $chain = [];
        foreach ($ordered as $name) {
            $provider = $this->providers[$name] ?? null;
            if ($provider !== null && $provider->isConfigured()) {
                $chain[] = $provider;
            }
        }

        return $chain;
    }
}
