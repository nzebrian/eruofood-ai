<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Pest configuration
|------------------------------------------------------------------------------
| Bind the Laravel TestCase to suites that need the framework (feature tests).
| Pure unit tests — including domain tests inside each module — need no
| framework and use Pest's default test case.
|
| As new modules are added, register their Feature test directories here so
| their HTTP/integration tests boot the application.
*/

use Tests\TestCase;

uses(TestCase::class)->in('Feature');
uses(TestCase::class)->in(
    __DIR__.'/../modules/Platform/tests/Feature',
    __DIR__.'/../modules/Identity/tests/Feature',
    __DIR__.'/../modules/Catalog/tests/Feature',
    __DIR__.'/../modules/Ai/tests/Feature',
    __DIR__.'/../modules/Nutrition/tests/Feature',
    __DIR__.'/../modules/Marketplace/tests/Feature',
    __DIR__.'/../modules/Commerce/tests/Feature',
    __DIR__.'/../modules/Payments/tests/Feature',
    __DIR__.'/../modules/Notifications/tests/Feature',
    __DIR__.'/../modules/Analytics/tests/Feature',
    __DIR__.'/../modules/Admin/tests/Feature',
    __DIR__.'/../modules/Search/tests/Feature',
    __DIR__.'/../modules/Support/tests/Feature',
);
