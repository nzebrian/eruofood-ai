<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Application-wide service provider.
 *
 * Holds only cross-cutting concerns (global bindings, macros, policies that are
 * not owned by a specific bounded context). Business wiring belongs in each
 * module's own service provider, never here.
 */
class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        //
    }
}
