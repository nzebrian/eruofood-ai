<?php

declare(strict_types=1);

use EruoFood\Catalog\Domain\Event\FoodPublished;
use EruoFood\Shared\Domain\EventBus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Seed a published food row directly in the Catalog table and return its id.
 *
 * @param list<string> $states
 * @param list<string> $tags
 */
function seedFood(string $name, string $region, string $description = '', array $states = [], array $tags = []): string
{
    $id = (string) Str::orderedUuid();
    DB::table('catalog_foods')->insert([
        'id' => $id,
        'name' => $name,
        'slug' => Str::slug($name).'-'.substr($id, 0, 8),
        'description' => $description,
        'category_id' => (string) Str::orderedUuid(),
        'region' => $region,
        'states' => json_encode($states),
        'local_names' => json_encode([]),
        'nutrition' => json_encode(['calories' => 500]),
        'images' => json_encode([]),
        'tags' => json_encode($tags),
        'status' => 'published',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

/** Index a food purely by publishing its domain event (no direct call into Search). */
function indexFood(string $name, string $region, string $description = '', array $states = [], array $tags = []): string
{
    $id = seedFood($name, $region, $description, $states, $tags);
    app(EventBus::class)->publish(new FoodPublished($id));

    return $id;
}

it('indexes a food from a published domain event and finds it — no direct search by any module', function (): void {
    indexFood('Jollof Rice', 'South West', 'Smoky Nigerian party rice with chicken', ['Lagos'], ['party']);

    $this->getJson('/api/v1/search?q=jollof')
        ->assertOk()
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.hits.0.document.title', 'Jollof Rice')
        ->assertJsonPath('data.hits.0.document.type', 'food');
});

it('matches a synonym (party rice → jollof)', function (): void {
    indexFood('Jollof Rice', 'South West', 'Nigerian party rice', ['Lagos'], []);

    $this->getJson('/api/v1/search?'.http_build_query(['q' => 'party rice']))
        ->assertOk()
        ->assertJsonPath('data.total', 1);
});

it('applies filters and facets', function (): void {
    indexFood('Lagos Jollof', 'South West', 'rice', ['Lagos'], []);
    indexFood('Kano Rice', 'North West', 'rice', ['Kano'], []);

    $this->getJson('/api/v1/search?'.http_build_query(['q' => 'rice', 'region' => 'South West']))
        ->assertOk()
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.hits.0.document.title', 'Lagos Jollof');

    // Facets reflect the full match set of the unfiltered query.
    $this->getJson('/api/v1/search?q=rice')
        ->assertOk()
        ->assertJsonPath('data.total', 2);
});

it('serves autocomplete and trending', function (): void {
    indexFood('Jollof Rice', 'South West', 'rice');
    indexFood('Fried Rice', 'South West', 'rice');

    $this->getJson('/api/v1/search/autocomplete?q=jol')
        ->assertOk()
        ->assertJsonPath('data.suggestions.0', 'Jollof Rice');

    // Run enough searches so trending has data. M39-SEC-001: a term needs
    // `search.public_term_min_occurrences` occurrences before it may be shown
    // publicly, so two searches is deliberately below the default of three.
    $this->getJson('/api/v1/search?q=rice');
    $this->getJson('/api/v1/search?q=rice');

    // M39-SEC-002. This assertion used to be `->assertOk()` and nothing else,
    // which is how a leaking endpoint passes its own test: the body was never
    // looked at. Assert the CONTENT.
    $belowThreshold = $this->getJson('/api/v1/search/trending')->assertOk();
    expect($belowThreshold->json('data.trending'))->not->toContain('rice');

    $this->getJson('/api/v1/search?q=rice');

    $atThreshold = $this->getJson('/api/v1/search/trending')->assertOk();
    expect($atThreshold->json('data.trending'))->toContain('rice');
});

it('removes a document when its source is no longer published', function (): void {
    $id = indexFood('Temp Dish', 'South West', 'rice');
    $this->getJson('/api/v1/search?q=temp')->assertJsonPath('data.total', 1);

    // Unpublish at source, then re-emit the event: the index entry is dropped.
    DB::table('catalog_foods')->where('id', $id)->update(['status' => 'draft']);
    app(EventBus::class)->publish(new FoodPublished($id));

    $this->getJson('/api/v1/search?q=temp')->assertJsonPath('data.total', 0);
});
