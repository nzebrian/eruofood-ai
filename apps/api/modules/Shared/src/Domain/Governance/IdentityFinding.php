<?php

declare(strict_types=1);

namespace EruoFood\Shared\Domain\Governance;

use EruoFood\Shared\Domain\Environment\FindingSeverity;
use EruoFood\Shared\Domain\Exception\InvalidArgumentException;

/**
 * One reason an identity configuration cannot be activated, or should not be
 * without somebody looking at it first.
 *
 * Carries a remedy for the same reason {@see \EruoFood\Shared\Domain\Environment\EnvironmentFinding}
 * does: the person reading `RELEASE_ACTOR_MISSING` is usually not the person
 * who wrote the rule, and "what do I type instead" is the only question they
 * actually have.
 *
 * Severity is reused from the environment policy rather than redeclared.
 * `Error` blocks activation; `Warning` is legal but wants a decision — a
 * governance role owned by the person who authors every pull request, say.
 */
final readonly class IdentityFinding
{
    private function __construct(
        public string $code,
        public FindingSeverity $severity,
        public string $summary,
        public string $remedy,
        public ?GovernanceRole $role,
    ) {
    }

    public static function error(string $code, string $summary, string $remedy, ?GovernanceRole $role = null): self
    {
        return self::of($code, FindingSeverity::Error, $summary, $remedy, $role);
    }

    public static function warning(string $code, string $summary, string $remedy, ?GovernanceRole $role = null): self
    {
        return self::of($code, FindingSeverity::Warning, $summary, $remedy, $role);
    }

    public static function of(
        string $code,
        FindingSeverity $severity,
        string $summary,
        string $remedy,
        ?GovernanceRole $role = null,
    ): self {
        foreach (['code' => $code, 'summary' => $summary, 'remedy' => $remedy] as $field => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException(sprintf('An identity finding needs a %s.', $field));
            }
        }

        return new self($code, $severity, $summary, $remedy, $role);
    }

    public function isError(): bool
    {
        return $this->severity === FindingSeverity::Error;
    }
}
