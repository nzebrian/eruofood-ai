<?php

declare(strict_types=1);

// Exercises the full vertical slice: route → controller → query handler →
// domain → resource, and asserts the standard response shape.
it('returns platform status from the module endpoint', function (): void {
    $this->getJson('/api/v1/health')
        ->assertOk()
        ->assertJsonStructure([
            'data' => ['status', 'service', 'version', 'environment', 'timestamp'],
        ])
        ->assertJsonPath('data.status', 'ok');
});
