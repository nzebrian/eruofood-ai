<?php

declare(strict_types=1);

namespace EruoFood\Shared\Infrastructure\Provider;

use EruoFood\Shared\Domain\Clock;
use EruoFood\Shared\Domain\EventBus;
use EruoFood\Shared\Domain\Idempotency\IdempotencyStore;
use EruoFood\Shared\Domain\TransactionManager;
use EruoFood\Shared\Infrastructure\Bus\LaravelEventBus;
use EruoFood\Shared\Infrastructure\Clock\SystemClock;
use EruoFood\Shared\Infrastructure\Idempotency\EloquentIdempotencyStore;
use EruoFood\Shared\Infrastructure\Transaction\LaravelTransactionManager;
use Illuminate\Contracts\Config\Repository as Config;
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

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Persistence/Migration');
    }
}
