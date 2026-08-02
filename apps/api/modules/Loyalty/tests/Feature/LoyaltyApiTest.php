<?php

declare(strict_types=1);

use EruoFood\Identity\Infrastructure\Persistence\Eloquent\Model\UserModel;
use EruoFood\Loyalty\Infrastructure\Seeder\LoyaltySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

/**
 * Register a user and return [token, id]. Optionally promote to an admin role.
 *
 * @return array{token: string, id: string}
 */
function loyaltyUser(object $test, string $email, bool $admin = false): array
{
    Mail::fake();
    $data = $test->postJson('/api/v1/auth/register', [
        'name' => 'Member', 'email' => $email,
        'password' => 'Password123', 'password_confirmation' => 'Password123',
    ])->assertCreated()->json('data');

    if ($admin) {
        UserModel::query()->where('email', $email)->update(['roles' => ['admin']]);
        $token = $test->postJson('/api/v1/auth/login', ['email' => $email, 'password' => 'Password123'])
            ->json('data.tokens.access_token');

        return ['token' => $token, 'id' => $data['user']['id']];
    }

    return ['token' => $data['tokens']['access_token'], 'id' => $data['user']['id']];
}

it('exposes the tier ladder and rewards catalogue publicly', function (): void {
    $this->seed(LoyaltySeeder::class);
    $this->getJson('/api/v1/loyalty/tiers')->assertOk()->assertJsonPath('data.0.key', 'bronze');
    $this->getJson('/api/v1/loyalty/rewards')->assertOk()->assertJsonPath('meta.total', 3);
});

it('opens an empty account on first read', function (): void {
    ['token' => $token] = loyaltyUser($this, 'a@example.com');
    $this->withToken($token)->getJson('/api/v1/loyalty/me')
        ->assertOk()
        ->assertJsonPath('data.balance', 0)
        ->assertJsonPath('data.tier.key', 'bronze');
});

it('lets an admin adjust points, moving the balance, ledger and tier', function (): void {
    ['token' => $member, 'id' => $memberId] = loyaltyUser($this, 'm@example.com');
    ['token' => $admin] = loyaltyUser($this, 'admin@example.com', admin: true);

    $this->withToken($admin)->postJson('/api/v1/loyalty/admin/adjust', [
        'user_id' => $memberId, 'points' => 1500, 'reason' => 'goodwill',
    ])->assertOk()->assertJsonPath('data.balance', 1500)->assertJsonPath('data.tier.key', 'silver');

    $this->withToken($member)->getJson('/api/v1/loyalty/ledger')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.points', 1500);
});

it('redeems a reward, issuing a voucher and debiting points', function (): void {
    $this->seed(LoyaltySeeder::class);
    ['token' => $member, 'id' => $memberId] = loyaltyUser($this, 'r@example.com');
    ['token' => $admin] = loyaltyUser($this, 'admin2@example.com', admin: true);

    $this->withToken($admin)->postJson('/api/v1/loyalty/admin/adjust', [
        'user_id' => $memberId, 'points' => 1000, 'reason' => 'seed',
    ])->assertOk();

    $reward = $this->getJson('/api/v1/loyalty/rewards')->json('data.0');

    $redemption = $this->withToken($member)->postJson("/api/v1/loyalty/rewards/{$reward['id']}/redeem")
        ->assertCreated()
        ->assertJsonPath('data.status', 'issued')
        ->json('data');

    expect($redemption['code'])->toStartWith('EFR-')
        ->and($redemption['points_spent'])->toBe($reward['points_cost']);

    $this->withToken($member)->getJson('/api/v1/loyalty/me')
        ->assertJsonPath('data.balance', 1000 - $reward['points_cost']);
});

it('rejects redeeming a reward the member cannot afford', function (): void {
    $this->seed(LoyaltySeeder::class);
    ['token' => $member] = loyaltyUser($this, 's@example.com');
    $reward = $this->getJson('/api/v1/loyalty/rewards')->json('data.0');

    $this->withToken($member)->postJson("/api/v1/loyalty/rewards/{$reward['id']}/redeem")
        ->assertStatus(422);
});

it('issues a referral code and attributes a referee, rejecting reuse and self-referral', function (): void {
    ['token' => $referrer] = loyaltyUser($this, 'ref@example.com');
    ['token' => $referee] = loyaltyUser($this, 'new@example.com');

    $code = $this->withToken($referrer)->getJson('/api/v1/loyalty/referrals/code')
        ->assertOk()->json('data.code');

    // A referrer cannot use their own code.
    $this->withToken($referrer)->postJson('/api/v1/loyalty/referrals/apply', ['code' => $code])
        ->assertStatus(409);

    // A new member can.
    $this->withToken($referee)->postJson('/api/v1/loyalty/referrals/apply', ['code' => $code])
        ->assertCreated()->assertJsonPath('data.status', 'pending');

    // But only once.
    $this->withToken($referee)->postJson('/api/v1/loyalty/referrals/apply', ['code' => $code])
        ->assertStatus(409);
});

it('forbids a non-admin from adjusting points', function (): void {
    ['token' => $member, 'id' => $memberId] = loyaltyUser($this, 'x@example.com');
    $this->withToken($member)->postJson('/api/v1/loyalty/admin/adjust', [
        'user_id' => $memberId, 'points' => 100, 'reason' => 'nope',
    ])->assertStatus(403);
});
