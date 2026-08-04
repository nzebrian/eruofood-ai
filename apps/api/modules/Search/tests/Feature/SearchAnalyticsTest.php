<?php

declare(strict_types=1);

use EruoFood\Identity\Infrastructure\Persistence\Eloquent\Model\UserModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

function searchAdminToken(object $test, string $email = 'search-admin@example.com'): string
{
    Mail::fake();
    $test->postJson('/api/v1/auth/register', [
        'name' => 'Search Admin', 'email' => $email,
        'password' => 'Password123', 'password_confirmation' => 'Password123',
    ])->assertCreated();
    UserModel::query()->where('email', $email)->update(['roles' => ['admin']]);

    return $test->postJson('/api/v1/auth/login', ['email' => $email, 'password' => 'Password123'])
        ->json('data.tokens.access_token');
}

it('records searches and surfaces metrics + failed searches to admins', function (): void {
    indexFood('Jollof Rice', 'South West', 'party rice');

    // Two hits and one zero-result search.
    $this->getJson('/api/v1/search?q=jollof')->assertOk()->assertJsonPath('data.total', 1);
    $this->getJson('/api/v1/search?q=jollof')->assertOk();
    $this->getJson('/api/v1/search?q=nonexistentdish')->assertOk()->assertJsonPath('data.total', 0);

    $token = searchAdminToken($this);

    $this->withToken($token)->getJson('/api/v1/search/admin/metrics')
        ->assertOk()
        ->assertJsonPath('data.total_searches', 3)
        ->assertJsonPath('data.zero_result_searches', 1);

    $this->withToken($token)->getJson('/api/v1/search/admin/failed')
        ->assertOk()
        ->assertJsonPath('data.terms.0.term', 'nonexistentdish');
});

it('attributes a result click for click-through rate', function (): void {
    indexFood('Jollof Rice', 'South West', 'party rice');

    $response = $this->getJson('/api/v1/search?q=jollof')->assertOk()->json('data');
    $queryId = $response['query_id'];
    $documentId = $response['hits'][0]['document']['id'];

    $this->postJson('/api/v1/search/click', [
        'query_id' => $queryId,
        'document_id' => $documentId,
        'position' => 0,
    ])->assertNoContent();

    $token = searchAdminToken($this);
    $this->withToken($token)->getJson('/api/v1/search/admin/metrics')
        ->assertOk()
        ->assertJsonPath('data.clicks', 1);
});

it('rejects admin analytics for non-admins', function (): void {
    Mail::fake();
    $this->postJson('/api/v1/auth/register', [
        'name' => 'Plain', 'email' => 'plain-search@example.com',
        'password' => 'Password123', 'password_confirmation' => 'Password123',
    ])->assertCreated();
    $token = $this->postJson('/api/v1/auth/login', ['email' => 'plain-search@example.com', 'password' => 'Password123'])
        ->json('data.tokens.access_token');

    $this->withToken($token)->getJson('/api/v1/search/admin/metrics')->assertStatus(403);
});
