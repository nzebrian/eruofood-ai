<?php

declare(strict_types=1);

namespace EruoFood\Shared\Domain\Environment;

/**
 * The environments this platform is allowed to run in.
 *
 * ## Why an enum rather than a string comparison
 *
 * `APP_ENV` is free text. Code that asks `if ($env === 'production')` treats
 * every typo — `prod`, `Production`, `producton` — as *not* production, which
 * means a misspelt deploy silently relaxes every production-only protection at
 * once: debug output, TLS enforcement, cookie flags, the payment provider.
 *
 * {@see fromName()} returns null for anything it does not recognise, and the
 * policy treats an unrecognised environment as the most dangerous case rather
 * than the safest. That is the fail-closed half of "environment selection must
 * fail safely": we would rather refuse to boot than boot as something we cannot
 * name.
 */
enum DeploymentEnvironment: string
{
    case Local = 'local';
    case Testing = 'testing';
    case Staging = 'staging';
    case Production = 'production';

    public static function fromName(?string $name): ?self
    {
        return self::tryFrom(strtolower(trim((string) $name)));
    }

    /** Environments that face real users and therefore real consequences. */
    public function isDeployed(): bool
    {
        return $this === self::Staging || $this === self::Production;
    }

    /**
     * May this environment talk to a live payment provider?
     *
     * Only production. Staging exists to rehearse, and a rehearsal that moves
     * real money is not a rehearsal — it is an unreviewed production change
     * with a friendlier hostname.
     */
    public function mayUseLivePaymentProvider(): bool
    {
        return $this === self::Production;
    }
}
