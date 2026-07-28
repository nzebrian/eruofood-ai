<?php

declare(strict_types=1);

use EruoFood\Identity\Infrastructure\Persistence\Eloquent\Model\UserModel;
use EruoFood\Support\Infrastructure\Seeder\SupportSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

/**
 * Register a user and return [token, id]. Optionally promote to an agent (admin role).
 *
 * @return array{token: string, id: string}
 */
function supportUser(object $test, string $email, bool $agent = false): array
{
    Mail::fake();
    $data = $test->postJson('/api/v1/auth/register', [
        'name' => 'Support User', 'email' => $email,
        'password' => 'Password123', 'password_confirmation' => 'Password123',
    ])->assertCreated()->json('data');

    if ($agent) {
        UserModel::query()->where('email', $email)->update(['roles' => ['admin']]);
        $token = $test->postJson('/api/v1/auth/login', ['email' => $email, 'password' => 'Password123'])
            ->json('data.tokens.access_token');

        return ['token' => $token, 'id' => $data['user']['id']];
    }

    return ['token' => $data['tokens']['access_token'], 'id' => $data['user']['id']];
}

it('runs a full ticket lifecycle: open, agent reply, resolve, CSAT', function (): void {
    $this->seed(SupportSeeder::class);
    ['token' => $customer] = supportUser($this, 'cust@example.com');
    ['token' => $agent] = supportUser($this, 'agent@example.com', agent: true);

    // Customer opens a ticket (SLA applied from the seeded policy).
    $ticket = $this->withToken($customer)->postJson('/api/v1/support/tickets', [
        'subject' => 'Payment failed', 'category' => 'billing', 'body' => 'My card was charged twice.',
        'priority' => 'high',
    ])->assertCreated()->assertJsonPath('data.status', 'new')->json('data');

    expect($ticket['ref'])->toStartWith('EF-')
        ->and($ticket['sla']['resolution_due_at'])->not->toBeNull();

    $id = $ticket['id'];

    // Agent picks it up and replies.
    $this->withToken($agent)->postJson("/api/v1/support/agent/tickets/{$id}/assign")->assertOk();
    $this->withToken($agent)->postJson("/api/v1/support/agent/tickets/{$id}/reply", [
        'body' => 'Apologies — refunding the duplicate now.',
    ])->assertOk()->assertJsonPath('data.status', 'open');

    // Agent resolves.
    $this->withToken($agent)->putJson("/api/v1/support/agent/tickets/{$id}/status", ['status' => 'resolved'])
        ->assertOk()->assertJsonPath('data.status', 'resolved');

    // Customer only sees public messages (no internal notes leaked).
    $this->withToken($customer)->getJson("/api/v1/support/tickets/{$id}")
        ->assertOk()->assertJsonPath('data.status', 'resolved');

    // Customer rates the resolution.
    $this->withToken($customer)->postJson("/api/v1/support/tickets/{$id}/csat", ['score' => 5, 'comment' => 'Fast!'])
        ->assertCreated()->assertJsonPath('data.score', 5);
});

it('hides internal notes from the customer view', function (): void {
    $this->seed(SupportSeeder::class);
    ['token' => $customer, 'id' => $customerId] = supportUser($this, 'c2@example.com');
    ['token' => $agent] = supportUser($this, 'a2@example.com', agent: true);

    $id = $this->withToken($customer)->postJson('/api/v1/support/tickets', [
        'subject' => 'Question', 'category' => 'general', 'body' => 'How do I...',
    ])->json('data.id');

    $this->withToken($agent)->postJson("/api/v1/support/agent/tickets/{$id}/notes", ['body' => 'internal: check KYC'])->assertOk();

    // Agent sees the internal note; customer does not.
    $this->withToken($agent)->getJson("/api/v1/support/agent/tickets/{$id}")
        ->assertOk()->assertJsonCount(2, 'data.messages');
    $this->withToken($customer)->getJson("/api/v1/support/tickets/{$id}")
        ->assertOk()->assertJsonCount(1, 'data.messages');
});

it('blocks non-agents from the agent workspace', function (): void {
    ['token' => $customer] = supportUser($this, 'c3@example.com');
    $this->withToken($customer)->getJson('/api/v1/support/agent/tickets')->assertStatus(403);
});
