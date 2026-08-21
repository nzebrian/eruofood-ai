<?php

declare(strict_types=1);

namespace EruoFood\Shared\Domain\Environment;

/**
 * The rules that decide whether a deployment is configured safely enough to run.
 *
 * ## Where these rules came from
 *
 * Not from a checklist. Each one exists because the configuration it forbids
 * either has already bitten this platform or is one env-var edit away from
 * doing so:
 *
 * - `PAYMENTS_UNPINNED` is M27's CI failure written down. CI copied
 *   `.env.example`, `APP_ENV` was `local`, and `payments.default` therefore
 *   resolved to a *live* gateway — the concurrency harness spent six scenarios
 *   attempting genuine bank transfers before anyone noticed. Nothing about that
 *   was specific to CI. Any environment that does not state its provider gets
 *   whichever one the config default happens to name.
 *
 * - `PAYMENTS_LIVE_OUTSIDE_PRODUCTION` is the same defect with the safety net
 *   removed: a staging box holding real Paystack credentials is a production
 *   payment system that nobody is watching.
 *
 * - The `SETTLEMENT_ORDER_*` rules encode the activation order declared beside
 *   the flags themselves. Enabling `settlement.execute` while
 *   `settlement.compute` is off does not fail loudly; it just means the thing
 *   paying merchants is working from figures that no stage of the pipeline has
 *   produced.
 *
 * ## Fail closed
 *
 * An unrecognised `APP_ENV` is an error, not a shrug. Every other rule here
 * asks "is this production?", and a typo answers "no" to all of them at once.
 *
 * The policy returns findings; it does not decide what to do about them. The
 * console command exits non-zero on any {@see FindingSeverity::Error}, and CI
 * gates on that.
 */
final class EnvironmentPolicy
{
    /**
     * Values that mean "nobody has set this yet", compared lower-cased.
     *
     * `change_me` is matched as a prefix rather than exactly, because the
     * templates carry variations (`change_me_in_local_only`) and a credential
     * that begins with those words is never a real one.
     */
    private const PLACEHOLDERS = ['__set__', 'secret', 'password', 'changeme', ''];

    /**
     * The M27 settlement flags, in the order they may be switched on.
     *
     * Each entry may only be enabled when every entry before it already is.
     *
     * @var list<string>
     */
    private const SETTLEMENT_ORDER = [
        'settlement.accrual',
        'settlement.accrual_posting',
        'settlement.compute',
        'settlement.execute',
    ];

    /** @return list<EnvironmentFinding> */
    public function evaluate(EnvironmentSnapshot $snapshot): array
    {
        $environment = $snapshot->environment();

        if ($environment === null) {
            return [EnvironmentFinding::error(
                'ENV_UNRECOGNISED',
                sprintf(
                    'APP_ENV is "%s", which is not one of: %s.',
                    (string) $snapshot->appEnvRaw,
                    implode(', ', array_column(DeploymentEnvironment::cases(), 'value')),
                ),
                'Set APP_ENV to a recognised environment. Every production-only protection is keyed off this '
                    .'value, so an unrecognised name silently disables all of them at once.',
            )];
        }

        return array_merge(
            $this->applicationRules($snapshot, $environment),
            $this->paymentRules($snapshot, $environment),
            $this->persistenceRules($snapshot, $environment),
            $this->settlementRules($snapshot, $environment),
        );
    }

    /** @return list<EnvironmentFinding> */
    private function applicationRules(EnvironmentSnapshot $snapshot, DeploymentEnvironment $environment): array
    {
        $findings = [];

        if ($environment === DeploymentEnvironment::Production && $snapshot->appDebug) {
            $findings[] = EnvironmentFinding::error(
                'APP_DEBUG_IN_PRODUCTION',
                'APP_DEBUG is true in production.',
                'Set APP_DEBUG=false. Debug mode returns stack traces — which for this platform include '
                    .'SQL, ledger identifiers and provider payloads — to whoever triggered the error.',
            );
        }

        if ($environment->isDeployed() && $this->isBlank($snapshot->appKey)) {
            $findings[] = EnvironmentFinding::error(
                'APP_KEY_MISSING',
                'APP_KEY is not set in a deployed environment.',
                'Generate a key and store it in the secret manager. Without it, encrypted columns — including '
                    .'saved payment methods — cannot be read or written.',
            );
        }

        if ($environment->isDeployed() && strtolower((string) $snapshot->logLevel) === 'debug') {
            $findings[] = EnvironmentFinding::error(
                'LOG_LEVEL_DEBUG',
                sprintf('LOG_LEVEL is "debug" in %s.', $environment->value),
                'Use info or warning. Debug logging records request and provider payloads, which is how bank '
                    .'account numbers end up in a log aggregator.',
            );
        }

        if ($environment->isDeployed() && ! $snapshot->sessionSecureCookie) {
            $findings[] = EnvironmentFinding::error(
                'SESSION_COOKIE_INSECURE',
                sprintf('SESSION_SECURE_COOKIE is false in %s.', $environment->value),
                'Set SESSION_SECURE_COOKIE=true so the session cookie is never sent over plaintext HTTP.',
            );
        }

        if ($environment->isDeployed() && strtolower((string) $snapshot->filesystemDisk) === 'local') {
            $findings[] = EnvironmentFinding::error(
                'FILESYSTEM_LOCAL',
                sprintf('FILESYSTEM_DISK is "local" in %s.', $environment->value),
                'Use object storage. Container-local disk is lost on restart, which for this platform means '
                    .'losing KYC/KYB evidence documents that a regulator may later ask for.',
            );
        }

        if ($environment === DeploymentEnvironment::Production
            && strtolower((string) $snapshot->queueConnection) === 'sync') {
            $findings[] = EnvironmentFinding::error(
                'QUEUE_SYNC_IN_PRODUCTION',
                'QUEUE_CONNECTION is "sync" in production.',
                'Use the redis queue. Sync runs every queued job inside the web request that dispatched it, so a '
                    .'slow provider call becomes a user-facing timeout and a failed job is lost with the request.',
            );
        }

        return $findings;
    }

    /** @return list<EnvironmentFinding> */
    private function paymentRules(EnvironmentSnapshot $snapshot, DeploymentEnvironment $environment): array
    {
        $findings = [];
        $live = $snapshot->isLivePaymentProvider();

        if (! $snapshot->paymentsProviderPinned) {
            $findings[] = $environment->isDeployed()
                ? EnvironmentFinding::error(
                    'PAYMENTS_UNPINNED',
                    sprintf(
                        'PAYMENTS_PROVIDER is not set in %s; the provider resolved to "%s" from the config default.',
                        $environment->value,
                        (string) $snapshot->paymentsProvider,
                    ),
                    'Set PAYMENTS_PROVIDER explicitly. An unpinned provider is how M27\'s concurrency harness '
                        .'came to attempt real bank transfers in CI: nothing was misconfigured, nothing was '
                        .'stated either, and the default was a live gateway.',
                )
                : EnvironmentFinding::warning(
                    'PAYMENTS_UNPINNED',
                    sprintf(
                        'PAYMENTS_PROVIDER is not set; the provider resolved to "%s" from the config default.',
                        (string) $snapshot->paymentsProvider,
                    ),
                    'Pin PAYMENTS_PROVIDER=mock so the offline provider is a stated choice rather than a '
                        .'coincidence of APP_ENV.',
                );
        }

        if ($live && ! $environment->mayUseLivePaymentProvider()) {
            $findings[] = EnvironmentFinding::error(
                'PAYMENTS_LIVE_OUTSIDE_PRODUCTION',
                sprintf(
                    'A live payment provider ("%s") is configured in %s.',
                    (string) $snapshot->paymentsProvider,
                    $environment->value,
                ),
                'Use PAYMENTS_PROVIDER=mock, or a provider sandbox with sandbox credentials. A non-production '
                    .'environment holding live credentials can move real money, and nobody is watching it.',
            );
        }

        if (! $live && $environment === DeploymentEnvironment::Production) {
            $findings[] = EnvironmentFinding::error(
                'PAYMENTS_OFFLINE_IN_PRODUCTION',
                sprintf('Production is configured with the offline provider "%s".', (string) $snapshot->paymentsProvider),
                'Point production at a real gateway. The offline provider reports success without transferring '
                    .'anything, so the platform would record payouts that no bank ever made.',
            );
        }

        return $findings;
    }

    /** @return list<EnvironmentFinding> */
    private function persistenceRules(EnvironmentSnapshot $snapshot, DeploymentEnvironment $environment): array
    {
        $findings = [];

        if (! $environment->isDeployed()) {
            return $findings;
        }

        if ($this->isPlaceholder($snapshot->dbPassword)) {
            $findings[] = EnvironmentFinding::error(
                'DB_PASSWORD_PLACEHOLDER',
                sprintf('The database password in %s is empty or a template placeholder.', $environment->value),
                'Inject the real credential from the secret manager at deploy time.',
            );
        }

        if (! in_array(strtolower((string) $snapshot->dbSslMode), ['require', 'verify-ca', 'verify-full'], true)) {
            $findings[] = EnvironmentFinding::error(
                'DB_TLS_NOT_REQUIRED',
                sprintf(
                    'DB_SSLMODE is "%s" in %s; TLS to the database is not enforced.',
                    (string) $snapshot->dbSslMode,
                    $environment->value,
                ),
                'Set DB_SSLMODE=require (verify-full where the CA is pinned). Ledger and payout rows cross this '
                    .'connection.',
            );
        }

        if ($this->isPlaceholder($snapshot->redisPassword)) {
            $findings[] = EnvironmentFinding::error(
                'REDIS_UNAUTHENTICATED',
                sprintf('Redis has no password in %s.', $environment->value),
                'Set REDIS_PASSWORD. Redis holds sessions, rate-limit counters and the queue; an unauthenticated '
                    .'instance lets anyone who can reach it replay or drop queued financial work.',
            );
        }

        if (strtolower((string) $snapshot->redisScheme) !== 'tls') {
            $findings[] = EnvironmentFinding::warning(
                'REDIS_TLS_NOT_ENABLED',
                sprintf('REDIS_SCHEME is "%s" in %s.', (string) $snapshot->redisScheme, $environment->value),
                'Prefer REDIS_SCHEME=tls. Acceptable only where the platform guarantees the link is private; '
                    .'record that decision if you keep it.',
            );
        }

        if ($this->isBlank($snapshot->redisPrefix)) {
            $findings[] = EnvironmentFinding::error(
                'REDIS_PREFIX_MISSING',
                sprintf('Redis keys are unprefixed in %s.', $environment->value),
                'Set a per-environment prefix. Two environments sharing a Redis instance with no prefix share '
                    .'sessions, locks and queues — staging can then consume production jobs.',
            );
        }

        return $findings;
    }

    /** @return list<EnvironmentFinding> */
    private function settlementRules(EnvironmentSnapshot $snapshot, DeploymentEnvironment $environment): array
    {
        $findings = [];

        // The activation order, checked as an invariant rather than a
        // convention. Each stage produces what the next one consumes.
        foreach (self::SETTLEMENT_ORDER as $index => $flag) {
            if ($index === 0 || ! $snapshot->flag($flag)) {
                continue;
            }

            $prerequisite = self::SETTLEMENT_ORDER[$index - 1];
            if (! $snapshot->flag($prerequisite)) {
                $findings[] = EnvironmentFinding::error(
                    'SETTLEMENT_ORDER_VIOLATED',
                    sprintf('"%s" is enabled while its prerequisite "%s" is not.', $flag, $prerequisite),
                    sprintf(
                        'Enable %s first, or disable %s. The stages are ordered because each one produces what '
                            .'the next consumes; skipping one means paying against figures nothing derived.',
                        $prerequisite,
                        $flag,
                    ),
                );
            }
        }

        // The combination that actually loses money: a switch that pays people,
        // pointed at a gateway that really pays them, somewhere nobody watches.
        if ($snapshot->flag('settlement.execute') && ! $environment->mayUseLivePaymentProvider()
            && $snapshot->isLivePaymentProvider()) {
            $findings[] = EnvironmentFinding::error(
                'SETTLEMENT_EXECUTE_LIVE_OUTSIDE_PRODUCTION',
                sprintf('settlement.execute is enabled in %s against a live provider.', $environment->value),
                'Disable settlement.execute. This configuration transfers real money from a non-production '
                    .'deployment.',
            );
        }

        if ($snapshot->flag('settlement.execute') && ! $snapshot->isLivePaymentProvider()
            && $environment === DeploymentEnvironment::Production) {
            $findings[] = EnvironmentFinding::error(
                'SETTLEMENT_EXECUTE_AGAINST_OFFLINE_PROVIDER',
                'settlement.execute is enabled in production against the offline provider.',
                'The platform would mark merchants paid while no transfer left the bank. Either point production '
                    .'at a real gateway or disable settlement.execute.',
            );
        }

        if ($snapshot->flag('settlement.auto_approve')) {
            $findings[] = EnvironmentFinding::warning(
                'SETTLEMENT_FOUR_EYES_RELAXED',
                'settlement.auto_approve is enabled; some runs will pay out without a named approver.',
                'Confirm this is a deliberate, documented decision. It removes the separation of duties that the '
                    .'aggregate and the database CHECK otherwise enforce.',
            );
        }

        foreach ($snapshot->scheduledTasks as $name => $enabled) {
            if ($enabled && str_contains($name, 'settlement')) {
                $findings[] = EnvironmentFinding::warning(
                    'FINANCIAL_SCHEDULE_ENABLED',
                    sprintf('Scheduled financial task "%s" is enabled.', $name),
                    'Confirm this is intended. Financial work on a timer needs an owner watching its output.',
                );
            }
        }

        return $findings;
    }

    private function isBlank(?string $value): bool
    {
        return $value === null || trim($value) === '';
    }

    private function isPlaceholder(?string $value): bool
    {
        $normalised = strtolower(trim((string) $value));

        return in_array($normalised, self::PLACEHOLDERS, true)
            || str_starts_with($normalised, 'change_me');
    }
}
