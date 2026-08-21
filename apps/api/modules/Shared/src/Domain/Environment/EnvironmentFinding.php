<?php

declare(strict_types=1);

namespace EruoFood\Shared\Domain\Environment;

use EruoFood\Shared\Domain\Exception\InvalidArgumentException;

/**
 * One thing that is wrong, or suspicious, about how an environment is configured.
 *
 * A finding carries a remedy because a validator that reports "REDIS_TLS not
 * set" at 3am and stops there has done half a job. The operator reading it is
 * usually not the person who wrote the rule.
 */
final readonly class EnvironmentFinding
{
    private function __construct(
        public string $code,
        public FindingSeverity $severity,
        public string $summary,
        public string $remedy,
    ) {
    }

    public static function error(string $code, string $summary, string $remedy): self
    {
        return self::of($code, FindingSeverity::Error, $summary, $remedy);
    }

    public static function warning(string $code, string $summary, string $remedy): self
    {
        return self::of($code, FindingSeverity::Warning, $summary, $remedy);
    }

    public static function of(string $code, FindingSeverity $severity, string $summary, string $remedy): self
    {
        foreach (['code' => $code, 'summary' => $summary, 'remedy' => $remedy] as $field => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException(sprintf('An environment finding needs a %s.', $field));
            }
        }

        return new self($code, $severity, $summary, $remedy);
    }
}
