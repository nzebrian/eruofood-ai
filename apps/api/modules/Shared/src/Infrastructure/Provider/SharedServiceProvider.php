<?php

declare(strict_types=1);

namespace EruoFood\Shared\Infrastructure\Provider;

use EruoFood\Shared\Domain\Clock;
use EruoFood\Shared\Domain\DataLifecycle\RetentionRegistry;
use EruoFood\Shared\Domain\Environment\EnvironmentPolicy;
use EruoFood\Shared\Domain\EventBus;
use EruoFood\Shared\Domain\Flag\FeatureFlag;
use EruoFood\Shared\Domain\Flag\FlagEvaluator;
use EruoFood\Shared\Domain\Flag\FlagRegistry;
use EruoFood\Shared\Domain\Idempotency\IdempotencyStore;
use EruoFood\Shared\Domain\Risk\RiskEvaluator;
use EruoFood\Shared\Domain\Schedule\Cadence;
use EruoFood\Shared\Domain\Schedule\ScheduledTask;
use EruoFood\Shared\Domain\Schedule\ScheduleRegistry;
use EruoFood\Shared\Domain\TransactionManager;
use EruoFood\Shared\Infrastructure\Bus\LaravelEventBus;
use EruoFood\Shared\Infrastructure\Clock\SystemClock;
use EruoFood\Shared\Infrastructure\Console\PurgeIdempotencyKeysCommand;
use EruoFood\Shared\Infrastructure\Console\TimezoneManifestCommand;
use EruoFood\Shared\Infrastructure\Console\VerifyEnvironmentCommand;
use EruoFood\Shared\Infrastructure\Correlation\PropagatesCorrelationToQueue;
use EruoFood\Shared\Infrastructure\Flag\ConfigFlagEvaluator;
use EruoFood\Shared\Infrastructure\Idempotency\EloquentIdempotencyStore;
use EruoFood\Shared\Infrastructure\Risk\NullRiskEvaluator;
use EruoFood\Shared\Infrastructure\Transaction\LaravelTransactionManager;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;

/**
 * Shared Kernel service provider.
 *
 * Binds cross-cutting ports (interfaces) to their infrastructure
 * implementations in the container. Every module depends on these abstractions
 * rather than concretions (Dependency Inversion Principle).
 */
final class SharedServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> port => adapter bindings */
    public array $bindings = [
        Clock::class => SystemClock::class,
        EventBus::class => LaravelEventBus::class,
    ];

    public function register(): void
    {
        // A singleton so every module's provider adds to the same registry,
        // whatever order they register in.
        $this->app->singleton(ScheduleRegistry::class);

        $this->app->singleton(FlagRegistry::class, function (): FlagRegistry {
            $registry = new FlagRegistry();

            foreach ($this->declaredFlags() as $flag) {
                $registry->register($flag);
            }

            return $registry;
        });

        $this->app->singleton(FlagEvaluator::class, fn (): ConfigFlagEvaluator => new ConfigFlagEvaluator(
            $this->app->make(FlagRegistry::class),
            $this->app->make('config'),
        ));

        // Stateless rules; a singleton so the console command and any future
        // readiness probe judge a deployment by exactly the same policy.
        $this->app->singleton(EnvironmentPolicy::class);

        // The abuse-detection seam. Allows everything until M29 provides a
        // real evaluator; binding one is then a container change rather than a
        // change to checkout, payments and dispatch.
        $this->app->singleton(RiskEvaluator::class, NullRiskEvaluator::class);

        // Declared retention, in one place. The registry does not delete
        // anything — acting on it is a separate, flagged, dry-runnable job.
        $this->app->singleton(RetentionRegistry::class, fn (): RetentionRegistry => RetentionRegistry::platformDefaults());

        $this->app->singleton(TransactionManager::class, function (): LaravelTransactionManager {
            /** @var Config $config */
            $config = $this->app->make('config');

            return new LaravelTransactionManager(
                $this->app->make(DatabaseManager::class),
                (int) $config->get('shared.transaction.attempts', 3),
            );
        });

        $this->app->singleton(IdempotencyStore::class, function (): EloquentIdempotencyStore {
            /** @var Config $config */
            $config = $this->app->make('config');

            return new EloquentIdempotencyStore(
                $this->app->make(Clock::class),
                (int) $config->get('shared.idempotency.ttl', 86400),
            );
        });
    }

    /**
     * Every high-risk capability the platform can switch on, and how to switch
     * it back off.
     *
     * Declared here rather than in each module's provider so the answer to
     * "what is switchable, and who owns it" is one file. Two of these describe
     * capabilities that already shipped with their own env switch (M25 routed
     * pricing, M26 dispatch); registering them changes nothing about how they
     * are read today — it makes them visible to the operator report alongside
     * everything else.
     *
     * @return list<FeatureFlag>
     */
    private function declaredFlags(): array
    {
        return [
            FeatureFlag::of(
                key: 'dispatch.engine',
                safeDefault: false,
                description: 'Automatic rider dispatch: candidate discovery, scoring and offers. Manual vendor assignment works with this off.',
                owner: 'Dispatch / operations',
                rolloutStrategy: 'One city first, watching offer acceptance rate and time-to-assign, then by region.',
                rollbackStrategy: 'Set DISPATCH_ENGINE_ENABLED=false. In-flight assignments continue; no new offers are made. No migration and no deploy.',
            ),
            FeatureFlag::of(
                key: 'pricing.routed',
                safeDefault: false,
                description: 'Routed delivery pricing from real road distance, rather than the merchant zone fee.',
                owner: 'Geo / commercial',
                rolloutStrategy: 'Per merchant, comparing quoted fee against the zone fee before widening.',
                rollbackStrategy: 'Disable the flag; pricing falls back to the merchant zone fee. Quotes already given are honoured.',
            ),
            FeatureFlag::of(
                key: 'dispatch.stale_rider_sweep',
                safeDefault: false,
                description: 'Marks riders unavailable when their location heartbeat goes stale, and releases offers they can no longer answer.',
                owner: 'Dispatch / operations',
                rolloutStrategy: 'Run in report-only mode first and compare the count against the operator view before enabling the write.',
                rollbackStrategy: 'Disable the flag; the sweep stops. Riders it already marked offline can go back online from the app.',
            ),
            FeatureFlag::of(
                key: 'notifications.retry',
                safeDefault: false,
                description: 'Automatic retry of failed notification deliveries with backoff, up to a permanent-failure ceiling.',
                owner: 'Notifications',
                rolloutStrategy: 'Enable for transactional categories first; marketing last.',
                rollbackStrategy: 'Disable the flag; failed notifications stay failed and are not retried. No message is sent twice.',
            ),
            FeatureFlag::of(
                key: 'lifecycle.retention_purge',
                safeDefault: false,
                description: 'Scheduled deletion and anonymisation of data past its declared retention period.',
                owner: 'Compliance / platform',
                rolloutStrategy: 'Dry-run reporting counts per category for a full cycle before the first destructive run.',
                rollbackStrategy: 'Disable the flag. Note that completed deletions are not reversible — this is why the dry run is not optional.',
            ),
            FeatureFlag::of(
                key: 'payments.orchestrator',
                safeDefault: false,
                description: 'M27 payment orchestration. Not implemented; declared so the switch exists and is visibly off.',
                owner: 'Payments',
                rolloutStrategy: 'Not yet applicable.',
                rollbackStrategy: 'Not yet applicable.',
            ),
            FeatureFlag::of(
                key: 'settlement.new_flow',
                safeDefault: false,
                description: 'Route settlement through M27 SettlementRun rather than the legacy M22 Settlement path.',
                owner: 'Payments',
                rolloutStrategy: 'Enable once one merchant has been settled end to end through the new path and reconciled against the legacy figures.',
                rollbackStrategy: 'Disable the flag; the legacy path serves. Runs already created stay readable and can be cancelled.',
            ),

            /*
             * M27 settlement, switched on in the order below and no other.
             *
             * The order is not a preference. Each stage makes the next one's
             * mistakes cheap: accruals that nobody acts on, then ledger
             * movements with no payout, then drafts that move nothing, then
             * reconciliation that only reads — and only then the flag that
             * actually pays somebody.
             */
            FeatureFlag::of(
                key: 'settlement.accrual',
                safeDefault: false,
                description: 'Record a payable accrual when an order becomes financially final. Writes rows; moves no money and posts no ledger entries on its own.',
                owner: 'Payments / finance',
                rolloutStrategy: 'Enable first, with settlement.accrual_posting off, for a full settlement cycle. Compare accrual totals against the figures finance produces by hand before going further.',
                rollbackStrategy: 'Disable the flag; accruals stop being written. Nothing is at risk — no money has moved, and the missing accruals are re-derivable from the ledger.',
            ),
            FeatureFlag::of(
                key: 'settlement.accrual_posting',
                safeDefault: false,
                description: 'Additionally post Escrow → MerchantPayable for each accrual, which is what makes an accrual settleable.',
                owner: 'Payments / finance',
                rolloutStrategy: 'Only after a full report-only cycle has been reconciled. This is the flag that ends report-only mode.',
                rollbackStrategy: 'Disable the flag; new accruals are recorded report-only and cannot be settled. Accruals already posted keep their ledger entries — reversing those is a compensating posting, not a flag.',
            ),
            FeatureFlag::of(
                key: 'settlement.compute',
                safeDefault: false,
                description: 'Build draft settlement runs from unsettled accruals. Drafts are reviewable and move nothing.',
                owner: 'Payments / finance',
                rolloutStrategy: 'Enable once accrual posting is on. Review drafts against manual figures for a cycle before enabling execution.',
                rollbackStrategy: 'Disable the flag; no new drafts are computed. Existing drafts can still be cancelled, and cancelling releases their accruals.',
            ),
            FeatureFlag::of(
                key: 'settlement.execute',
                safeDefault: false,
                description: 'Actually transfer money to merchants. The financial kill switch.',
                owner: 'Payments / finance',
                rolloutStrategy: 'One merchant, then a small cohort, then all — each stage reconciled before the next. Never enabled at the same time as any other settlement flag.',
                rollbackStrategy: 'Disable the flag. No new payout attempt is created; attempts already submitted are left alone and reconciled, because abandoning an in-flight transfer is how its outcome becomes unknown.',
            ),
            FeatureFlag::of(
                key: 'settlement.auto_approve',
                safeDefault: false,
                description: 'Approve a settlement run without a human when it falls below a configured threshold.',
                owner: 'Payments / finance',
                rolloutStrategy: 'Not part of the M27 rollout. Declared so the capability is visible and visibly off; enabling it removes the four-eyes rule for small runs and needs its own decision.',
                rollbackStrategy: 'Disable the flag; every run needs a named approver again.',
            ),
            FeatureFlag::of(
                key: 'settlement.reconcile',
                safeDefault: false,
                description: 'Scheduled reconcilers comparing provider to platform, ledger to wallets, payable to settled, and payments to accruals.',
                owner: 'Payments / finance',
                rolloutStrategy: 'Enable read-only alongside compute. Triage the first batch of cases by hand before enabling execution.',
                rollbackStrategy: 'Disable the flag; the sweeps stop. Cases already opened stay open and are unaffected — a reconciler never closes a case it cannot prove.',
            ),
        ];
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Persistence/Migration');

        if ($this->app->runningInConsole()) {
            $this->commands([
                TimezoneManifestCommand::class,
                VerifyEnvironmentCommand::class,
                PurgeIdempotencyKeysCommand::class,
            ]);
        }

        // Registered unconditionally, matching Search and Payments. The registry
        // is a *description* of recurring work, and a description that only
        // exists in a console process cannot be inspected, audited or asserted
        // on from anywhere else.
        $this->registerRetentionWork();

        // Correlation across the queue boundary. Registered in boot() rather
        // than register() because it needs the event dispatcher, and it is
        // registered unconditionally: a tracing feature that only works when
        // somebody remembers to switch it on is not a tracing feature.
        PropagatesCorrelationToQueue::register($this->app->make(Dispatcher::class));
    }

    /**
     * Retention work the Shared kernel owns, registered DISABLED (M42).
     *
     * Two independent locks sit under this task and both are off. `enabled` is
     * false here, and `destructiveRetention: true` additionally subjects it to
     * {@see \EruoFood\Shared\Domain\DataLifecycle\RetentionGate} — the
     * `lifecycle.retention_purge` flag, whose safe default is also false. Either
     * one alone stops an unattended run.
     *
     * That redundancy is deliberate rather than belt-and-braces theatre:
     * `DeletionMode::isReversible()` is true for exactly one mode and this is
     * not it, so a task flipped on by accident should still do nothing.
     *
     * `verification:purge` is registered here too. The command has existed since
     * M24 and was never scheduled, which left `verification.identity_documents`
     * with an enforcement path nobody could reach without typing it by hand.
     */
    private function registerRetentionWork(): void
    {
        $registry = $this->app->make(ScheduleRegistry::class);

        $registry->register(ScheduledTask::of(
            name: 'shared:purge-idempotency-keys',
            command: 'shared:purge-idempotency-keys',
            cadence: Cadence::Daily,
            enabled: false,
            description: 'Deletes idempotency claims past expires_at, honouring shared.idempotency_keys '
                .'(retainDays 1, Destroy). Eligibility is expires_at, never created_at — deleting a live '
                .'claim would reopen the duplicate-payment window it exists to close. Prints counts only, '
                .'never keys, snapshots or user ids. Disabled by default.',
            destructiveRetention: true,
        ));

        $registry->register(ScheduledTask::of(
            name: 'verification:purge',
            command: 'verification:purge',
            cadence: Cadence::Daily,
            enabled: false,
            description: 'Deletes verification identity-document metadata past the retention window in '
                .'verification.privacy.metadata_retention_days, honouring verification.identity_documents '
                .'(Destroy). The command predates M42; this registration is what makes it reachable '
                .'unattended. Disabled by default.',
            destructiveRetention: true,
        ));
    }
}
