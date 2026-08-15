<?php

declare(strict_types=1);

namespace EruoFood\Shared\Domain\Flag;

/**
 * Why a flag evaluated the way it did.
 *
 * "The feature is off" is not actionable. "The feature is off because the
 * environment kill switch is set" and "the feature is off because this merchant
 * is outside the rollout" lead to completely different next steps, and during an
 * incident the difference is the difference between a one-minute fix and an
 * hour of guessing.
 */
final readonly class FlagDecision
{
    private function __construct(
        public string $key,
        public bool $enabled,
        public FlagReason $reason,
        public ?string $detail = null,
    ) {
    }

    public static function of(string $key, bool $enabled, FlagReason $reason, ?string $detail = null): self
    {
        return new self($key, $enabled, $reason, $detail);
    }

    public function explain(): string
    {
        $state = $this->enabled ? 'enabled' : 'disabled';
        $because = $this->reason->explain();

        return $this->detail === null
            ? "'{$this->key}' is {$state}: {$because}"
            : "'{$this->key}' is {$state}: {$because} ({$this->detail})";
    }
}
