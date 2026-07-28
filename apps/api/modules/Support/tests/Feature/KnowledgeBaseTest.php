<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('authors, publishes and publicly serves a help article', function (): void {
    ['token' => $agent] = supportUser($this, 'kb-agent@example.com', agent: true);

    $article = $this->withToken($agent)->postJson('/api/v1/support/kb/manage/articles', [
        'title' => 'How to reset your password', 'category' => 'account',
        'body' => 'Open settings, choose security, then reset password.',
    ])->assertCreated()->assertJsonPath('data.status', 'draft')->json('data');

    // Drafts are not public yet.
    $this->getJson('/api/v1/support/kb/articles/'.$article['slug'])->assertStatus(404);

    $this->withToken($agent)->postJson("/api/v1/support/kb/manage/articles/{$article['id']}/publish")
        ->assertOk()->assertJsonPath('data.status', 'published');

    // Now public browse + read + vote work.
    $this->getJson('/api/v1/support/kb/articles?q=password')->assertOk()->assertJsonPath('data.0.title', 'How to reset your password');
    $this->getJson('/api/v1/support/kb/articles/'.$article['slug'])->assertOk();
    $this->postJson('/api/v1/support/kb/articles/'.$article['slug'].'/vote', ['helpful' => true])
        ->assertOk()->assertJsonPath('data.helpful_yes', 1);
});

it('rejects article authoring by non-agents', function (): void {
    ['token' => $customer] = supportUser($this, 'kb-cust@example.com');
    $this->withToken($customer)->postJson('/api/v1/support/kb/manage/articles', [
        'title' => 'x', 'category' => 'y', 'body' => 'z',
    ])->assertStatus(403);
});
