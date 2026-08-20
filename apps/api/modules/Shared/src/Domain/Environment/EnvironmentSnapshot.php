<?php

declare(strict_types=1);

namespace EruoFood\Shared\Domain\Environment;

/**
 * Everything {@see EnvironmentPolicy} needs to judge a running deployment,
 * captured once so the rules stay pure.
 *
 * The policy takes a snapshot rather than reading `config()` itself for one
 * practical reason: a rule that reaches into global state can only be tested by
 * mutating global state, and a test suite that rewrites `APP_ENV` to check the
 * production rules is one ordering change away from running the *rest* of the
 * suite as production. Here the dangerous configurations are plain arrays.
 *
 * `paymentsProviderPinned` is deliberately separate from `paymentsProvider`.
 * Knowing which provider resolved is not enough — M27's CI failure was caused by
 * a provider that resolved to a live gateway because nobody had pinned one, and
 * a snapshot that recorded only the outcome could not tell that from a
 * deliberate choice.
 */
final readonly class EnvironmentSnapshot
{
    /**
     * @param array<string, bool> $flags resolved feature-flag states, keyed by flag key
     * @param array<string, bool> $scheduledTasks registered task name => enabled
     */
    public function __construct(
        public ?string $appEnvRaw,
        public bool $appDebug,
        public ?string $appKey,
        public ?string $appName,
        public ?string $logLevel,
        public ?string $dbConnection,
        public ?string $dbDatabase,
        public ?string $dbPassword,
        public ?string $dbSslMode,
        public ?string $redisHost,
        public ?string $redisPassword,
        public ?string $redisScheme,
        public ?string $redisPrefix,
        public ?string $queueConnection,
        public bool $sessionSecureCookie,
        public ?string $filesystemDisk,
        public ?string $paymentsProvider,
        public bool $paymentsProviderPinned,
        public array $flags = [],
        public array $scheduledTasks = [],
    ) {
    }

    public function environment(): ?DeploymentEnvironment
    {
        return DeploymentEnvironment::fromName($this->appEnvRaw);
    }

    public function flag(string $key): bool
    {
        return $this->flags[$key] ?? false;
    }

    /**
     * Gateways that move real money when asked to.
     *
     * Listed here rather than inferred from "not mock" so that adding a new
     * adapter is a deliberate act: an unrecognised provider name is treated as
     * live by {@see isLivePaymentProvider()}, which is the safe way round.
     *
     * @return list<string>
     */
    public static function offlinePaymentProviders(): array
    {
        return ['mock', 'null', 'fake'];
    }

    /**
     * Gateways that speak a provider's real protocol against its test estate.
     *
     * Empty today, and the seam matters more than the contents. When staging is
     * pointed at a provider sandbox, the right move is to register it here under
     * its own identifier — `paystack_sandbox`, not `paystack` with different
     * keys. Distinguishing sandbox from live by which credential happens to be
     * mounted means the only thing preventing a real transfer is an environment
     * variable that looks identical whether it is right or wrong.
     *
     * @return list<string>
     */
    public static function sandboxPaymentProviders(): array
    {
        return [];
    }

    public function isLivePaymentProvider(): bool
    {
        if ($this->paymentsProvider === null || trim($this->paymentsProvider) === '') {
            // Nothing resolved is not the same as nothing configured; the
            // policy reports the unpinned case separately. Treated as live
            // because an empty provider that later resolves is a live one.
            return true;
        }

        $provider = strtolower($this->paymentsProvider);

        return ! in_array($provider, self::offlinePaymentProviders(), true)
            && ! in_array($provider, self::sandboxPaymentProviders(), true);
    }
}
