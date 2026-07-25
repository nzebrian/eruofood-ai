<?php

declare(strict_types=1);

namespace EruoFood\Platform\Infrastructure\Provider;

use EruoFood\Platform\Application\Query\GetSystemStatusHandler;
use EruoFood\Shared\Domain\Clock;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Platform module service provider.
 *
 * Demonstrates the standard module wiring every bounded context follows:
 *  - register DI bindings (resolving the use case with its dependencies),
 *  - load the module's own routes,
 *  - (later) load migrations, register events, commands, and policies.
 */
final class PlatformServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(GetSystemStatusHandler::class, function ($app): GetSystemStatusHandler {
            /** @var \Illuminate\Contracts\Config\Repository $config */
            $config = $app->make('config');

            return new GetSystemStatusHandler(
                clock: $app->make(Clock::class),
                service: (string) $config->get('app.name', 'EruoFood AI'),
                version: (string) $config->get('app.version', '0.1.0'),
                environment: (string) $config->get('app.env', 'production'),
            );
        });
    }

    public function boot(): void
    {
        // Apply the shared `api` prefix + middleware so module routes resolve
        // under /api/v1/... consistently across every bounded context.
        Route::prefix('api')
            ->middleware('api')
            ->group(__DIR__.'/../../Interface/Http/routes.php');
    }
}
