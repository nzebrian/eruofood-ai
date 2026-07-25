<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

/**
 * Base test case. Laravel 11+ resolves the application from bootstrap/app.php
 * automatically, so no CreatesApplication trait is required.
 */
abstract class TestCase extends BaseTestCase
{
}
