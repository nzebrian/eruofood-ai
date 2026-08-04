<?php

declare(strict_types=1);

// Readiness probe: asserts the endpoint reports the database and Redis checks
// and returns 200 when the backing services used by the test environment are
// reachable (SQLite + array/redis as configured in phpunit.xml).
it('reports readiness with per-dependency checks', function (): void {
    $this->getJson('/api/v1/ready')
        ->assertOk()
        ->assertJsonPath('status', 'ready')
        ->assertJsonStructure(['status', 'checks' => ['database', 'redis']]);
});
