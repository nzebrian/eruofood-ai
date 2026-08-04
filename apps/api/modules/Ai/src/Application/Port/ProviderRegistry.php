<?php

declare(strict_types=1);

namespace EruoFood\Ai\Application\Port;

use EruoFood\Ai\Domain\Enum\AiProviderName;

/**
 * Resolves configured {@see AiProvider} adapters and exposes the default +
 * ordered fallback chain the gateway walks when a provider fails.
 */
interface ProviderRegistry
{
    /** @throws \EruoFood\Ai\Domain\Exception\ProviderUnavailable */
    public function default(): AiProvider;

    /** @throws \EruoFood\Ai\Domain\Exception\ProviderUnavailable */
    public function get(AiProviderName $name): AiProvider;

    /**
     * The default provider followed by each configured fallback, in order and
     * de-duplicated — the exact sequence the gateway attempts.
     *
     * @return list<AiProvider>
     */
    public function resolutionChain(): array;
}
