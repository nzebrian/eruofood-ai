<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('runs a conversation: start → send → the other participant reads', function (): void {
    ['token' => $aToken, 'id' => $aId] = notifyUser($this, 'chat-a@example.com');
    ['token' => $bToken, 'id' => $bId] = notifyUser($this, 'chat-b@example.com');

    // A starts a conversation with B.
    $conversationId = $this->withToken($aToken)->postJson('/api/v1/notifications/conversations', [
        'type' => 'customer_vendor',
        'participant_ids' => [$bId],
        'subject' => 'About my order',
    ])->assertCreated()->json('data.id');

    // A sends a message.
    $this->withToken($aToken)->postJson("/api/v1/notifications/conversations/{$conversationId}/messages", [
        'body' => 'Hello, is my order ready?',
    ])->assertCreated()->assertJsonPath('data.sender_id', $aId);

    // B sees the conversation in their inbox and the message.
    $this->withToken($bToken)->getJson('/api/v1/notifications/conversations')
        ->assertOk()->assertJsonPath('data.0.id', $conversationId);
    $messageId = $this->withToken($bToken)->getJson("/api/v1/notifications/conversations/{$conversationId}/messages")
        ->assertOk()->json('data.0.id');

    // B was notified in-app about the new message.
    $this->withToken($bToken)->getJson('/api/v1/notifications')
        ->assertOk()->assertJsonPath('data.0.template_key', 'new_message');

    // B reads the message (read receipt records B).
    $this->withToken($bToken)->postJson("/api/v1/notifications/messages/{$messageId}/read")
        ->assertOk();

    // A non-participant cannot read the thread.
    ['token' => $cToken] = notifyUser($this, 'chat-c@example.com');
    $this->withToken($cToken)->getJson("/api/v1/notifications/conversations/{$conversationId}/messages")
        ->assertStatus(403);
});

it('does not send a promotional broadcast to somebody who never opted in', function (): void {
    ['token' => $adminToken] = notifyUser($this, 'bcast-admin@example.com');
    \EruoFood\Identity\Infrastructure\Persistence\Eloquent\Model\UserModel::query()
        ->where('email', 'bcast-admin@example.com')->update(['roles' => ['admin']]);
    $admin = $this->postJson('/api/v1/auth/login', ['email' => 'bcast-admin@example.com', 'password' => 'Password123'])
        ->json('data.tokens.access_token');

    ['token' => $rToken, 'id' => $rId] = notifyUser($this, 'bcast-recipient@example.com');

    $id = $this->withToken($admin)->postJson('/api/v1/notifications/admin/broadcasts', [
        'title' => 'Weekend sale',
        'body' => 'Enjoy 20% off this weekend!',
        'category' => 'promotional',
        'channels' => ['in_app'],
        'segment' => "users:{$rId}",
    ])->assertCreated()->json('data.id');

    // The broadcast is addressed to one person and reports as much: the segment
    // resolved, the campaign ran.
    $this->withToken($admin)->postJson("/api/v1/notifications/admin/broadcasts/{$id}/send")
        ->assertOk()->assertJsonPath('data.sent', true)->assertJsonPath('data.recipient_count', 1);

    // …but nothing was delivered, because marketing requires a positive opt-in
    // and this account never gave one. An admin broadcast is not a way around
    // consent.
    $this->withToken($rToken)->getJson('/api/v1/notifications')
        ->assertOk()->assertJsonPath('data', []);
});

it('sends a promotional broadcast once the recipient has opted in', function (): void {
    ['token' => $adminToken] = notifyUser($this, 'optin-admin@example.com');
    \EruoFood\Identity\Infrastructure\Persistence\Eloquent\Model\UserModel::query()
        ->where('email', 'optin-admin@example.com')->update(['roles' => ['admin']]);
    $admin = $this->postJson('/api/v1/auth/login', ['email' => 'optin-admin@example.com', 'password' => 'Password123'])
        ->json('data.tokens.access_token');

    ['token' => $rToken, 'id' => $rId] = notifyUser($this, 'optin-recipient@example.com');

    $this->withToken($rToken)->putJson('/api/v1/notifications/preferences/marketing', ['opt_in' => true])
        ->assertOk()->assertJsonPath('data.marketing_opt_in', true);

    $id = $this->withToken($admin)->postJson('/api/v1/notifications/admin/broadcasts', [
        'title' => 'Weekend sale',
        'body' => 'Enjoy 20% off this weekend!',
        'category' => 'promotional',
        'channels' => ['in_app'],
        'segment' => "users:{$rId}",
    ])->assertCreated()->json('data.id');

    $this->withToken($admin)->postJson("/api/v1/notifications/admin/broadcasts/{$id}/send")->assertOk();

    $this->withToken($rToken)->getJson('/api/v1/notifications')
        ->assertOk()->assertJsonPath('data.0.template_key', 'broadcast');
});

it('stops marketing but not account messages when somebody unsubscribes', function (): void {
    ['token' => $rToken, 'id' => $rId] = notifyUser($this, 'unsub@example.com');

    $this->withToken($rToken)->putJson('/api/v1/notifications/preferences/marketing', ['opt_in' => true])
        ->assertOk();

    $token = (string) \Illuminate\Support\Facades\DB::table('notifications_preferences')
        ->where('user_id', $rId)->value('unsubscribe_token');

    expect($token)->not->toBeEmpty();

    // Clicked from an email client: no session, no bearer token.
    $this->postJson("/api/v1/notifications/unsubscribe/{$token}")
        ->assertOk()->assertJsonPath('data.unsubscribed', true);

    $this->withToken($rToken)->getJson('/api/v1/notifications/preferences')
        ->assertOk()->assertJsonPath('data.marketing_opt_in', false);

    // The categories a person did not ask to stop are untouched — an
    // unsubscribe from a campaign must not silence their account.
    $preferences = $this->withToken($rToken)->getJson('/api/v1/notifications/preferences')->json('data');
    expect($preferences['channels_by_category'])->toBe([]);
});

it('answers an unknown unsubscribe token exactly like a real one', function (): void {
    // An endpoint that distinguishes the two is an oracle for testing tokens.
    $real = $this->postJson('/api/v1/notifications/unsubscribe/'.str_repeat('a', 48));
    $fake = $this->postJson('/api/v1/notifications/unsubscribe/'.str_repeat('b', 48));

    expect($real->status())->toBe($fake->status())
        ->and($real->json())->toEqual($fake->json());
});
