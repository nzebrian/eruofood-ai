<?php

declare(strict_types=1);

namespace EruoFood\Shared\Domain\Flag;

use EruoFood\Shared\Domain\Exception\InvalidArgumentException;

/**
 * A high-risk capability that can be switched on, and — the important half —
 * switched back off.
 *
 * ## What was wrong with the flags this replaces
 *
 * The platform already had flags: `DISPATCH_ENGINE_ENABLED`,
 * `VERIFICATION_ENFORCEMENT_ENABLED`, `GOOGLE_AUTH_ENABLED` and others, each an
 * `env()` call in its own config file. That works for the code reading them and
 * fails everybody else. There was no way to answer "what is currently switched
 * on in production", "who owns this", "what happens if we turn it off", or "can
 * we enable it for one city first" without reading every config file in the
 * repository.
 *
 * ## Every field here is required, and that is the point
 *
 * A flag with no owner is a flag nobody dares touch during an incident. A flag
 * with no documented rollback is one somebody will enable at 5pm on a Friday
 * having assumed there is one. The constructor refuses to build a flag that
 * cannot answer these questions, so the answers exist before the capability
 * does.
 *
 * ## Safe default
 *
 * `safeDefault` is what this flag is when nothing says otherwise — including
 * when the flag store is unreachable. For a high-risk capability that is
 * `false`, and {@see FlagEvaluator} falls back to it rather than failing open.
 * A feature-flag system whose outage silently enables everything is worse than
 * no feature-flag system.
 */
final readonly class FeatureFlag
{
    private function __construct(
        public string $key,
        public bool $safeDefault,
        public string $description,
        public string $owner,
        public string $rolloutStrategy,
        public string $rollbackStrategy,
    ) {
    }

    public static function of(
        string $key,
        bool $safeDefault,
        string $description,
        string $owner,
        string $rolloutStrategy,
        string $rollbackStrategy,
    ): self {
        foreach ([
            'key' => $key,
            'description' => $description,
            'owner' => $owner,
            'rollout strategy' => $rolloutStrategy,
            'rollback strategy' => $rollbackStrategy,
        ] as $label => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException("Feature flag '{$key}' needs a {$label}.");
            }
        }

        if (preg_match('/^[a-z0-9]+(\.[a-z0-9_]+)*$/', $key) !== 1) {
            // Dotted lower-case, so a flag key is safe in config paths, log
            // fields and env var derivation without escaping.
            throw new InvalidArgumentException(
                "Feature flag key '{$key}' must be dotted lower-case, e.g. 'dispatch.engine'.",
            );
        }

        return new self($key, $safeDefault, $description, $owner, $rolloutStrategy, $rollbackStrategy);
    }

    /**
     * The environment variable that overrides this flag.
     *
     * Derived rather than stored, so a flag's key and its env var cannot drift
     * apart: `dispatch.engine` → `FLAG_DISPATCH_ENGINE`.
     */
    public function environmentVariable(): string
    {
        return 'FLAG_'.strtoupper(str_replace('.', '_', $this->key));
    }

    public function isHighRisk(): bool
    {
        // A capability whose safe state is "off" is one where being wrong costs
        // something. Used by the operator report to sort the dangerous ones up.
        return $this->safeDefault === false;
    }
}
