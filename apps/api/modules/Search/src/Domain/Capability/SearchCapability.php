<?php

declare(strict_types=1);

namespace EruoFood\Search\Domain\Capability;

/**
 * A snapshot of what the search backend can actually do right now.
 *
 * Built by {@see \EruoFood\Search\Infrastructure\Capability\SearchCapabilityProbe}
 * from the live connection, never from configuration alone. Configuration says
 * what was asked for; this says what is true.
 */
final readonly class SearchCapability
{
    public function __construct(
        public string $driver,
        public CapabilityState $vector,
        public CapabilityState $vectorIndex,
        public CapabilityState $trigram,
        public CapabilityState $trigramIndex,
        public ?string $detail = null,
    ) {
    }

    /**
     * Native KNN is only "active" when the extension, the column and the index
     * are all genuinely present. Anything less is the portable PHP cosine
     * fallback, and it is reported as fallback rather than as success.
     */
    public function nativeVectorSearchActive(): bool
    {
        return $this->vector->isUsable() && $this->vectorIndex->isUsable();
    }

    public function trigramAccelerationActive(): bool
    {
        return $this->trigram->isUsable() && $this->trigramIndex->isUsable();
    }

    /**
     * Whether an operator should be looking at this. Configuration-disabled is
     * a choice, not a fault; unavailable and probe-failed are faults.
     */
    public function isDegraded(): bool
    {
        foreach ([$this->vector, $this->vectorIndex, $this->trigram, $this->trigramIndex] as $state) {
            if ($state->isDegraded()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'driver' => $this->driver,
            'vector_extension' => $this->vector->value,
            'vector_index' => $this->vectorIndex->value,
            'native_vector_search' => $this->nativeVectorSearchActive() ? 'active' : 'fallback',
            'trigram_extension' => $this->trigram->value,
            'trigram_index' => $this->trigramIndex->value,
            'trigram_acceleration' => $this->trigramAccelerationActive() ? 'active' : 'fallback',
            'degraded' => $this->isDegraded(),
            'detail' => $this->detail,
        ];
    }
}
