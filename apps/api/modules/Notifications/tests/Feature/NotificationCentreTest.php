<?php

declare(strict_types=1);

use EruoFood\Payments\Domain\Event\PaymentSucceeded;
use EruoFood\Shared\Domain\EventBus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

/**
 * Register a user and return [token, userId].
 *
 * @return array{token: string, id: string}
 */
function notifyUser(object $test, string $email): array
{
    Mail::fake();
    $data = $test->postJson('/api/v1/auth/register', [
        'name' => 'Notify User',
        'email' => $email,
        'password' => 'Password123',
        'password_confirmation' => 'Password123',
    ])->assertCreated()->json('data');

    return ['token' => $data['tokens']['access_token'], 'id' => $data['user']['id']];
}

it('turns a published domain event into an in-app notification (no direct call)', function (): void {
    ['token' => $token, 'id' => $userId] = notifyUser($this, 'evt@example.com');

    // A business module publishes an event on the shared bus — Payments here.
    app(EventBus::class)->publish(new PaymentSucceeded(
        'pay-1', 'order-1', $userId, 1000000, 'NGN', 'mock',
    ));

    // The Notifications context reacted and created the notification.
    $this->withToken($token)->getJson('/api/v1/notifications')
        ->assertOk()
        ->assertJsonPath('data.0.category', 'payment')
        ->assertJsonPath('data.0.template_key', 'payment_succeeded');

    $this->withToken($token)->getJson('/api/v1/notifications/unread-count')
        ->assertOk()->assertJsonPath('data.unread', 1);
});

it('marks notifications read', function (): void {
    ['token' => $token, 'id' => $userId] = notifyUser($this, 'read@example.com');
    app(EventBus::class)->publish(new PaymentSucceeded('pay-2', null, $userId, 500000, 'NGN', 'mock'));

    $id = $this->withToken($token)->getJson('/api/v1/notifications')->json('data.0.id');
    $this->withToken($token)->postJson("/api/v1/notifications/{$id}/read")->assertOk()->assertJsonPath('data.read', true);
    $this->withToken($token)->getJson('/api/v1/notifications/unread-count')->assertOk()->assertJsonPath('data.unread', 0);
});

it('honours preferences — disabling a channel stops that notification', function (): void {
    ['token' => $token, 'id' => $userId] = notifyUser($this, 'pref@example.com');

    // Restrict the payment category to email only (no in-app).
    $this->withToken($token)->putJson('/api/v1/notifications/preferences/channels', [
        'category' => 'payment', 'channels' => ['email'],
    ])->assertOk();

    app(EventBus::class)->publish(new PaymentSucceeded('pay-3', null, $userId, 500000, 'NGN', 'mock'));

    // No in-app notification was created for this user.
    $this->withToken($token)->getJson('/api/v1/notifications')->assertOk()->assertJsonCount(0, 'data');
});
