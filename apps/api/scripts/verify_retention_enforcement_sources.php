<?php

declare(strict_types=1);

/**
 * The one list of files M42's retention verification reads (M42).
 *
 * Shared by `verify_retention_enforcement.php`, which reads them, and
 * `m42_retention_negative_control.php`, which copies exactly these into its
 * disposable fixtures. Two hand-maintained copies would drift, and the failure
 * that drift produces is the worst kind: a fixture missing a file the validator
 * reads fails with "cannot read required source" instead of on the mutation
 * under test, so the control reports the validator as discriminating when it
 * never saw the change.
 *
 * @return array<string, string> logical name => repository-relative path
 */
function m42_retention_sources(): array
{
    return [
        'idempotency_command' => 'apps/api/modules/Shared/src/Infrastructure/Console/PurgeIdempotencyKeysCommand.php',
        'rider_command' => 'apps/api/modules/Geo/src/Infrastructure/Console/PurgeRiderLocationsCommand.php',
        'search_command' => 'apps/api/modules/Search/src/Infrastructure/Console/PurgeSearchQueryLogCommand.php',
        'idempotency_store' => 'apps/api/modules/Shared/src/Infrastructure/Idempotency/EloquentIdempotencyStore.php',
        'rider_repository' => 'apps/api/modules/Geo/src/Infrastructure/Persistence/Eloquent/EloquentRiderLocationRepository.php',
        'enforcement' => 'apps/api/modules/Shared/src/Domain/DataLifecycle/RetentionEnforcement.php',
        'registry' => 'apps/api/modules/Shared/src/Domain/DataLifecycle/RetentionRegistry.php',
        'gate' => 'apps/api/modules/Shared/src/Domain/DataLifecycle/RetentionGate.php',
        'bootstrap' => 'apps/api/bootstrap/app.php',
        'shared_provider' => 'apps/api/modules/Shared/src/Infrastructure/Provider/SharedServiceProvider.php',
        'geo_provider' => 'apps/api/modules/Geo/src/Infrastructure/Provider/GeoServiceProvider.php',
        'search_provider' => 'apps/api/modules/Search/src/Infrastructure/Provider/SearchServiceProvider.php',
        'flags_config' => 'apps/api/config/flags.php',
    ];
}
