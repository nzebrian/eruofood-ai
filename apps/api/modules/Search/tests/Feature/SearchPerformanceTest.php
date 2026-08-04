<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('ranks a query over a populated index within a latency budget', function (): void {
    // Populate the index with a realistic spread of documents.
    $regions = ['South West', 'North West', 'South East', 'North Central'];
    for ($i = 0; $i < 120; $i++) {
        $region = $regions[$i % 4];
        indexFood("Dish {$i} rice", $region, "A tasty rice dish number {$i}", [], ['staple']);
    }
    indexFood('Signature Jollof', 'South West', 'The definitive smoky party jollof rice', ['Lagos'], ['party']);

    $start = microtime(true);
    $response = $this->getJson('/api/v1/search?'.http_build_query([
        'q' => 'jollof rice',
        'per_page' => 10,
    ]))->assertOk();
    $elapsedMs = (microtime(true) - $start) * 1000;

    // Correctness: the exact match ranks first.
    $response->assertJsonPath('data.hits.0.document.title', 'Signature Jollof');

    // Performance: a single ranked query over ~120 docs stays well under budget.
    expect($elapsedMs)->toBeLessThan(2000.0);
})->group('performance');
