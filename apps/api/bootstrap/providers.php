<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Registered service providers.
|------------------------------------------------------------------------------
| The application provider wires cross-cutting concerns. Each bounded context
| registers itself through its own module provider — add new modules here as
| they are introduced. This list is the single index of active modules.
*/

return [
    App\Providers\AppServiceProvider::class,

    // ---- Bounded context / foundation modules ----
    EruoFood\Shared\Infrastructure\Provider\SharedServiceProvider::class,
    EruoFood\Platform\Infrastructure\Provider\PlatformServiceProvider::class,
];
