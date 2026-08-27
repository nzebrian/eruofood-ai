<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * M38-SEC-001 — every public read path enforces the same scope rule.
 *
 * Before M38 `isAdminOnly()` was checked in exactly one place,
 * `SearchService::search()`. `AutocompleteService` and `RecommendationService`
 * had no check at all, and `/autocomplete`, `/suggestions` and
 * `/recommendations` sit on the PUBLIC route group while happily accepting
 * `?type=user`. `suggest()` would have returned indexed user titles by prefix
 * to an anonymous caller.
 *
 * Nothing leaked only because no `UserSourceProvider` exists. These tests index
 * a user document DIRECTLY, so "that type is never indexed" cannot be mistaken
 * for a security control — which is precisely the mistake that was being made.
 */

/** Put a user document in the index, which no source provider does today. */
function indexUserDocument(string $title): string
{
    $sourceId = (string) Str::orderedUuid();

    DB::table('search_documents')->insert([
        'id' => 'user:'.$sourceId,
        'type' => 'user',
        'source_id' => $sourceId,
        'title' => $title,
        'description' => 'private profile',
        'search_text' => mb_strtolower($title.' private profile'),
        'keywords' => json_encode([]),
        'locale' => 'en',
        'facets' => json_encode([]),
        'popularity' => 100,
        'rating' => 0,
        'embedding' => json_encode([]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $sourceId;
}

beforeEach(function (): void {
    indexUserDocument('Adaeze Private Person');
});

/**
 * Every public Search read path, with the admin-only scope requested.
 *
 * Adding an endpoint without adding it here is the failure mode this dataset
 * exists to make expensive: the suite enumerates the routes it knows about, and
 * the accompanying negative control proves a single unguarded path fails.
 */
dataset('public read paths', [
    'search' => ['/api/v1/search?q=adaeze&type=user'],
    'autocomplete' => ['/api/v1/search/autocomplete?q=ada&type=user'],
    'suggestions' => ['/api/v1/search/suggestions?q=ada&type=user'],
    'recommendations' => ['/api/v1/search/recommendations?type=user&kind=trending'],
]);

it('refuses the admin-only scope on every public read path', function (string $url): void {
    $response = $this->getJson($url);

    // Whatever the repository's authorization status is, it must not be a
    // successful body carrying the private title.
    expect($response->status())->toBeGreaterThanOrEqual(400);
    expect($response->getContent())->not->toContain('Adaeze Private Person');
})->with('public read paths');

it('never leaks an admin-only title through an unauthenticated response body', function (string $url): void {
    expect($this->getJson($url)->getContent())->not->toContain('Adaeze');
})->with('public read paths');

it('still serves public scopes to anonymous callers', function (): void {
    // The gate must refuse the admin scope without breaking ordinary discovery.
    $this->getJson('/api/v1/search/autocomplete?q=ada')->assertOk();
    $this->getJson('/api/v1/search?q=ada')->assertOk();
    $this->getJson('/api/v1/search/recommendations?kind=trending&type=food')->assertOk();
});

it('excludes admin-only types from the global fan-out', function (): void {
    // `Global` must not quietly include `user`; if it did, the gate would pass
    // (Global is not admin-only) while the results contained user documents.
    $body = $this->getJson('/api/v1/search?q=adaeze&type=global')->assertOk()->getContent();

    expect($body)->not->toContain('Adaeze Private Person');
});

it('applies the gate before the result cache, so a warm entry cannot be replayed', function (): void {
    // Warm the cache as an admin would never have done — via a public scope —
    // then confirm the protected scope is still refused rather than served
    // from any cached entry.
    $this->getJson('/api/v1/search?q=ada')->assertOk();

    expect($this->getJson('/api/v1/search?q=ada&type=user')->status())
        ->toBeGreaterThanOrEqual(400);
});
