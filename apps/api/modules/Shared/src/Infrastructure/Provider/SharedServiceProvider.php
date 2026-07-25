<?php

declare(strict_types=1);

namespace EruoFood\Shared\Infrastructure\Provider;

use EruoFood\Shared\Domain\Clock;
use EruoFood\Shared\Domain\EventBus;
use EruoFood\Shared\Infrastructure\Bus\LaravelEventBus;
use EruoFood\Shared\Infrastructure\Clock\SystemClock;
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
        //
    }

    public function boot(): void
    {
        //
    }
}
