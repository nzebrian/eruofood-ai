<?php

declare(strict_types=1);

use EruoFood\Identity\Infrastructure\Persistence\Eloquent\Model\UserModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

/**
 * Register a user and return [token, id]. Optionally promote to a moderator role.
 *
 * @return array{token: string, id: string}
 */
function reviewsUser(object $test, string $email, bool $moderator = false): array
{
    Mail::fake();
    $data = $test->postJson('/api/v1/auth/register', [
        'name' => 'Reviewer', 'email' => $email,
        'password' => 'Password123', 'password_confirmation' => 'Password123',
    ])->assertCreated()->json('data');

    if ($moderator) {
        UserModel::query()->where('email', $email)->update(['roles' => ['admin']]);
        $token = $test->postJson('/api/v1/auth/login', ['email' => $email, 'password' => 'Password123'])
            ->json('data.tokens.access_token');

        return ['token' => $token, 'id' => $data['user']['id']];
    }

    return ['token' => $data['tokens']['access_token'], 'id' => $data['user']['id']];
}

it('submits a review that auto-publishes and updates the subject rating summary', function (): void {
    ['token' => $token] = reviewsUser($this, 'a@example.com');

    $review = $this->withToken($token)->postJson('/api/v1/reviews', [
        'subject_type' => 'vendor', 'subject_id' => 'vendor-1',
        'rating' => 5, 'title' => 'Great', 'body' => 'Fresh and fast',
    ])->assertCreated()->assertJsonPath('data.status', 'published')->json('data');

    expect($review['verified_purchase'])->toBeFalse();

    // Public summary reflects the published review.
    $this->getJson('/api/v1/reviews/vendor/vendor-1/summary')
        ->assertOk()
        ->assertJsonPath('data.count', 1)
        ->assertJsonPath('data.average', 5.0);

    // Public listing returns it.
    $this->getJson('/api/v1/reviews/vendor/vendor-1')
        ->assertOk()
        ->assertJsonPath('meta.total', 1);
});

it('rejects a second review of the same subject by the same author', function (): void {
    ['token' => $token] = reviewsUser($this, 'b@example.com');
    $payload = ['subject_type' => 'product', 'subject_id' => 'p1', 'rating' => 4];

    $this->withToken($token)->postJson('/api/v1/reviews', $payload)->assertCreated();
    $this->withToken($token)->postJson('/api/v1/reviews', $payload)->assertStatus(409);
});

it('holds a review that trips the content filter and lets a moderator approve it', function (): void {
    ['token' => $author] = reviewsUser($this, 'c@example.com');
    ['token' => $mod] = reviewsUser($this, 'mod@example.com', moderator: true);

    $review = $this->withToken($author)->postJson('/api/v1/reviews', [
        'subject_type' => 'vendor', 'subject_id' => 'vendor-9',
        'rating' => 1, 'body' => 'this vendor is a scam',
    ])->assertCreated()->assertJsonPath('data.status', 'pending')->json('data');

    // Not counted while pending.
    $this->getJson('/api/v1/reviews/vendor/vendor-9/summary')->assertJsonPath('data.count', 0);

    // Appears in the moderation queue.
    $this->withToken($mod)->getJson('/api/v1/reviews/moderation/queue')
        ->assertOk()->assertJsonPath('meta.total', 1);

    // Moderator approves → published and counted.
    $this->withToken($mod)->postJson("/api/v1/reviews/moderation/{$review['id']}/approve")
        ->assertOk()->assertJsonPath('data.status', 'published');

    $this->getJson('/api/v1/reviews/vendor/vendor-9/summary')->assertJsonPath('data.count', 1);
});

it('records a helpful vote on a published review', function (): void {
    ['token' => $token] = reviewsUser($this, 'd@example.com');
    $review = $this->withToken($token)->postJson('/api/v1/reviews', [
        'subject_type' => 'recipe', 'subject_id' => 'r1', 'rating' => 5,
    ])->json('data');

    $this->withToken($token)->postJson("/api/v1/reviews/{$review['id']}/vote", ['helpful' => true])
        ->assertOk()->assertJsonPath('data.helpful_yes', 1);
});

it('forbids a non-moderator from the moderation queue', function (): void {
    ['token' => $token] = reviewsUser($this, 'e@example.com');
    $this->withToken($token)->getJson('/api/v1/reviews/moderation/queue')->assertStatus(403);
});

it('lets the author edit their own review but not another user', function (): void {
    ['token' => $owner] = reviewsUser($this, 'f@example.com');
    ['token' => $other] = reviewsUser($this, 'g@example.com');

    $review = $this->withToken($owner)->postJson('/api/v1/reviews', [
        'subject_type' => 'food', 'subject_id' => 'f1', 'rating' => 3,
    ])->json('data');

    $this->withToken($owner)->putJson("/api/v1/reviews/{$review['id']}", ['rating' => 5])
        ->assertOk()->assertJsonPath('data.rating', 5);

    $this->withToken($other)->putJson("/api/v1/reviews/{$review['id']}", ['rating' => 1])
        ->assertStatus(403);
});
