<?php

declare(strict_types=1);

use EruoFood\Ai\Infrastructure\Seeder\DefaultPromptSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

function aiChatToken(object $test, string $email = 'chatty@example.com'): string
{
    Mail::fake();

    return $test->postJson('/api/v1/auth/register', [
        'name' => 'Chatty Cook',
        'email' => $email,
        'password' => 'Password123',
        'password_confirmation' => 'Password123',
    ])->assertCreated()->json('data.tokens.access_token');
}

it('starts a conversation, continues it, and exposes chat history', function (): void {
    $this->seed(DefaultPromptSeeder::class);
    $token = aiChatToken($this);

    // First turn starts a new thread.
    $first = $this->withToken($token)->postJson('/api/v1/ai/assistant/chat', [
        'message' => 'How do I make my jollof smoky?',
    ])->assertOk();

    $conversationId = $first->json('data.conversation_id');
    expect($conversationId)->not->toBeNull();
    $first->assertJsonPath('data.meta.provider', 'mock');
    expect($first->json('data.conversation.messages'))->toHaveCount(2); // user + assistant

    // Second turn continues the same thread.
    $this->withToken($token)->postJson('/api/v1/ai/assistant/chat', [
        'message' => 'And how do I stop it burning?',
        'conversation_id' => $conversationId,
    ])->assertOk()->assertJsonPath('data.conversation_id', $conversationId);

    // History list shows the thread with 4 messages.
    $this->withToken($token)->getJson('/api/v1/ai/conversations')
        ->assertOk()
        ->assertJsonPath('data.0.id', $conversationId)
        ->assertJsonPath('data.0.message_count', 4);

    // Full thread fetch.
    $this->withToken($token)->getJson("/api/v1/ai/conversations/{$conversationId}")
        ->assertOk()
        ->assertJsonPath('data.messages.0.role', 'user');

    // Delete it.
    $this->withToken($token)->deleteJson("/api/v1/ai/conversations/{$conversationId}")->assertNoContent();
    $this->withToken($token)->getJson("/api/v1/ai/conversations/{$conversationId}")->assertStatus(404);
});

it('does not let a user read another user\'s conversation', function (): void {
    $this->seed(DefaultPromptSeeder::class);
    $owner = aiChatToken($this, 'owner@example.com');
    $other = aiChatToken($this, 'other@example.com');

    $id = $this->withToken($owner)->postJson('/api/v1/ai/assistant/chat', ['message' => 'hi'])
        ->assertOk()->json('data.conversation_id');

    $this->withToken($other)->getJson("/api/v1/ai/conversations/{$id}")->assertStatus(404);
});

it('generates cooking tips as text', function (): void {
    $this->seed(DefaultPromptSeeder::class);
    $token = aiChatToken($this, 'tips@example.com');

    $this->withToken($token)->postJson('/api/v1/ai/assistant/tips', ['topic' => 'frying plantain'])
        ->assertOk()
        ->assertJsonPath('data.meta.provider', 'mock');
});
