<?php

declare(strict_types=1);

use EruoFood\Identity\Infrastructure\Persistence\Eloquent\Model\UserModel;
use EruoFood\Search\Domain\Enum\SearchType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * M38-SEC-001 — every public read path enforces the same scope rule.
 *
 * ## The original defect
 *
 * `isAdminOnly()` was checked in exactly one place, `SearchService::search()`.
 * `AutocompleteService` and `RecommendationService` had no check at all, and
 * `/autocomplete`, `/suggestions` and `/recommendations` sit on the PUBLIC route
 * group while happily accepting `?type=user`.
 *
 * ## The defect the first fix left behind
 *
 * Adding the gate refused an explicit `?type=user` and stopped there. It did not
 * refuse the DEFAULT request, because `SearchScopeGate` authorises a scope NAME
 * and two repository methods ignored what that name meant:
 * `EloquentSearchIndexRepository::suggest()` and `::popular()` both treated
 * `SearchType::Global` as "apply no type filter", while
 * `SearchType::documentTypes()` defined Global as the PUBLIC fan-out that
 * excludes `user`. Nothing reconciled the two, so:
 *
 *     GET /api/v1/search/autocomplete?q=ada            -> 200 "Adaeze Private Person"
 *     GET /api/v1/search/suggestions?q=ada             -> 200 "Adaeze Private Person"
 *     GET /api/v1/search/recommendations?kind=trending -> 200 the whole user document
 *
 * That is what the tests below reproduce and now forbid.
 *
 * ## Why the previous version of this file did not catch it
 *
 * It asserted `assertOk()` on `/autocomplete?q=ada` and nothing more — and
 * `q=ada` prefix-matches the seeded private title. The endpoint satisfied the
 * test by returning 200 WHILE LEAKING. Every assertion here now inspects the
 * response body; a 200 is never on its own accepted as evidence.
 */

/** Put a user document in the index, which no source provider does today. */
function indexUserDocument(string $title = 'Adaeze Private Person'): array
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
        // Deliberately the highest popularity in the index, so any query that
        // does not filter by type ranks it FIRST. A leak cannot hide behind
        // "it was there but off the end of the list".
        'popularity' => 10_000,
        'rating' => 5,
        'embedding' => json_encode([]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return ['source_id' => $sourceId, 'document_id' => 'user:'.$sourceId, 'title' => $title];
}

/** A public food document, so the endpoints have something legitimate to return. */
function indexPublicDocument(string $title = 'Adalu Beans And Corn'): string
{
    $sourceId = (string) Str::orderedUuid();

    DB::table('search_documents')->insert([
        'id' => 'food:'.$sourceId,
        'type' => 'food',
        'source_id' => $sourceId,
        'title' => $title,
        'description' => 'a public dish',
        'search_text' => mb_strtolower($title.' a public dish'),
        'keywords' => json_encode([]),
        'locale' => 'en',
        'facets' => json_encode([]),
        'popularity' => 5,
        'rating' => 4,
        'embedding' => json_encode([]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $sourceId;
}

function searchAuthAdminToken(object $test, string $email = 'search-scope-admin@example.com'): string
{
    Mail::fake();
    $test->postJson('/api/v1/auth/register', [
        'name' => 'Scope Admin', 'email' => $email,
        'password' => 'Password123', 'password_confirmation' => 'Password123',
    ])->assertCreated();
    UserModel::query()->where('email', $email)->update(['roles' => ['admin']]);

    return $test->postJson('/api/v1/auth/login', ['email' => $email, 'password' => 'Password123'])
        ->json('data.tokens.access_token');
}

beforeEach(function (): void {
    $this->protected = indexUserDocument();
    indexPublicDocument();
});

// =============================================================================
// 1. The reproduction: DEFAULT requests, no `type` parameter at all.
// =============================================================================

/**
 * The exact production route shapes that leaked against 5c3e9d8.
 *
 * No `type` parameter, so `ParsesSearchParams::searchType()` supplies
 * `SearchType::Global` — the request an ordinary anonymous visitor makes.
 */
dataset('default public read paths', [
    'search' => ['/api/v1/search?q=ada'],
    'autocomplete' => ['/api/v1/search/autocomplete?q=ada'],
    'suggestions' => ['/api/v1/search/suggestions?q=ada'],
    'recommendations' => ['/api/v1/search/recommendations?kind=trending'],
]);

it('never returns a protected user document on a default unauthenticated request', function (string $url): void {
    $response = $this->getJson($url);

    // The request is ALLOWED to succeed — Global is a public scope. What it may
    // not do is return the protected document.
    expect($response->status())->toBeLessThan(500);

    $body = $response->getContent();

    expect($body)->not->toContain($this->protected['title'])
        ->and($body)->not->toContain($this->protected['document_id'])
        ->and($body)->not->toContain($this->protected['source_id'])
        // `"type":"user"` cannot appear either: the presence of the type is a
        // leak even if the title happened to be blank.
        ->and($body)->not->toContain('"type":"user"');
})->with('default public read paths');

it('still serves genuine public results on those same default requests', function (): void {
    // The fix must not be "return nothing". The public document shares the
    // `ada` prefix with the protected one, so these assertions fail both if the
    // protected document leaks and if the endpoint has been broken shut.
    $autocomplete = $this->getJson('/api/v1/search/autocomplete?q=ada')->assertOk();
    expect($autocomplete->json('data.suggestions'))->toContain('Adalu Beans And Corn');

    $suggestions = $this->getJson('/api/v1/search/suggestions?q=ada')->assertOk();
    expect($suggestions->json('data.suggestions'))->toContain('Adalu Beans And Corn');

    $recommendations = $this->getJson('/api/v1/search/recommendations?kind=trending')->assertOk();
    expect($recommendations->json('data.items'))->not->toBeEmpty();

    $this->getJson('/api/v1/search?q=ada')->assertOk()->assertJsonPath('data.total', 1);
});

// =============================================================================
// 2. The explicit admin-only scope is still refused.
// =============================================================================

dataset('explicit user-scope read paths', [
    'search' => ['/api/v1/search?q=adaeze&type=user'],
    'autocomplete' => ['/api/v1/search/autocomplete?q=ada&type=user'],
    'suggestions' => ['/api/v1/search/suggestions?q=ada&type=user'],
    'recommendations' => ['/api/v1/search/recommendations?type=user&kind=trending'],
]);

it('refuses the admin-only scope on every public read path', function (string $url): void {
    $response = $this->getJson($url);

    expect($response->status())->toBeGreaterThanOrEqual(400)
        ->and($response->getContent())->not->toContain($this->protected['title'])
        ->and($response->getContent())->not->toContain($this->protected['source_id']);
})->with('explicit user-scope read paths');

it('names the refusal so a caller can act on it', function (): void {
    $this->getJson('/api/v1/search/autocomplete?q=ada&type=user')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'SEARCH_NOT_AUTHORIZED');
});

// =============================================================================
// 3. Explicit `type=global` — the same fan-out, spelled out.
// =============================================================================

dataset('explicit global read paths', [
    'search' => ['/api/v1/search?q=ada&type=global'],
    'autocomplete' => ['/api/v1/search/autocomplete?q=ada&type=global'],
    'suggestions' => ['/api/v1/search/suggestions?q=ada&type=global'],
    'recommendations' => ['/api/v1/search/recommendations?kind=trending&type=global'],
]);

it('excludes admin-only types from the global fan-out on every path', function (string $url): void {
    $body = $this->getJson($url)->getContent();

    expect($body)->not->toContain($this->protected['title'])
        ->and($body)->not->toContain($this->protected['source_id']);
})->with('explicit global read paths');

// =============================================================================
// 4. Administrators.
// =============================================================================

it('lets an authenticated administrator read the user scope', function (): void {
    $token = searchAuthAdminToken($this);

    $response = $this->withToken($token)->getJson('/api/v1/search/users?q=adaeze')->assertOk();

    // The protected scope is not forbidden knowledge — it is admin knowledge.
    // If this ever fails, the fix has broken the feature rather than secured it.
    expect($response->getContent())->toContain($this->protected['title']);
});

it('refuses the user scope to an authenticated NON-administrator', function (): void {
    Mail::fake();
    $this->postJson('/api/v1/auth/register', [
        'name' => 'Plain Person', 'email' => 'plain-scope@example.com',
        'password' => 'Password123', 'password_confirmation' => 'Password123',
    ])->assertCreated();

    $token = $this->postJson('/api/v1/auth/login', [
        'email' => 'plain-scope@example.com', 'password' => 'Password123',
    ])->json('data.tokens.access_token');

    $response = $this->withToken($token)->getJson('/api/v1/search/users?q=adaeze');

    expect($response->status())->toBeGreaterThanOrEqual(400)
        ->and($response->getContent())->not->toContain($this->protected['title']);
});

// =============================================================================
// 5. The invariant itself, independent of any route.
// =============================================================================

it('defines Global as a fan-out that contains no admin-only type', function (): void {
    $global = SearchType::Global->documentTypes();

    expect($global)->not->toContain(SearchType::User)
        ->and(SearchType::Global->documentTypeValues())->not->toContain('user');

    foreach ($global as $type) {
        expect($type->isAdminOnly())->toBeFalse()
            ->and($type)->not->toBe(SearchType::Global);
    }

    // Derived from cases(), so a future admin-only type is excluded without
    // anyone having to remember to edit a list.
    $expected = array_values(array_filter(
        SearchType::cases(),
        static fn (SearchType $t): bool => $t !== SearchType::Global && ! $t->isAdminOnly(),
    ));
    expect($global)->toBe($expected);
});

it('applies the gate before the result cache, so a warm entry cannot be replayed', function (): void {
    // Warm the cache via a public scope, then confirm the protected scope is
    // still refused rather than served from any cached entry.
    $this->getJson('/api/v1/search?q=ada')->assertOk();

    expect($this->getJson('/api/v1/search?q=ada&type=user')->status())
        ->toBeGreaterThanOrEqual(400);
});

it('keys the result cache by scope, so Global cannot collect an admin-only entry', function (): void {
    $token = searchAuthAdminToken($this);

    // An admin executes the protected scope, which populates the cache.
    $this->withToken($token)->getJson('/api/v1/search/users?q=adaeze')->assertOk();

    // The same term on the public scope must not pick that entry up.
    $body = $this->getJson('/api/v1/search?q=adaeze')->assertOk()->getContent();

    expect($body)->not->toContain($this->protected['title']);
});
