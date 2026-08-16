<?php

declare(strict_types=1);

namespace EruoFood\Shared\Infrastructure\Provider;

use EruoFood\Shared\Domain\Clock;
use EruoFood\Shared\Domain\DataLifecycle\RetentionRegistry;
use EruoFood\Shared\Domain\EventBus;
use EruoFood\Shared\Domain\Flag\FeatureFlag;
use EruoFood\Shared\Domain\Flag\FlagEvaluator;
use EruoFood\Shared\Domain\Flag\FlagRegistry;
use EruoFood\Shared\Domain\Idempotency\IdempotencyStore;
use EruoFood\Shared\Domain\Risk\RiskEvaluator;
use EruoFood\Shared\Domain\Schedule\ScheduleRegistry;
use EruoFood\Shared\Domain\TransactionManager;
use EruoFood\Shared\Infrastructure\Bus\LaravelEventBus;
use EruoFood\Shared\Infrastructure\Clock\SystemClock;
use EruoFood\Shared\Infrastructure\Console\TimezoneManifestCommand;
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
                description: 'M27 split settlement. Not implemented; declared so the switch exists and is visibly off.',
                owner: 'Payments',
                rolloutStrategy: 'Not yet applicable.',
                rollbackStrategy: 'Not yet applicable.',
            ),
        ];
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Persistence/Migration');

        if ($this->app->runningInConsole()) {
            $this->commands([TimezoneManifestCommand::class]);
        }

        // Correlation across the queue boundary. Registered in boot() rather
        // than register() because it needs the event dispatcher, and it is
        // registered unconditionally: a tracing feature that only works when
        // somebody remembers to switch it on is not a tracing feature.
        PropagatesCorrelationToQueue::register($this->app->make(Dispatcher::class));
    }
}
