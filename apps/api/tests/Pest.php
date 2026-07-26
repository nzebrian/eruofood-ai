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
);
