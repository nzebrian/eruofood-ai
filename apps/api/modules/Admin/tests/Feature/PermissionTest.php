<?php

declare(strict_types=1);

use EruoFood\Admin\Domain\Enum\AdminRole;
use EruoFood\Admin\Domain\Rbac\AdminAccount;
use EruoFood\Admin\Domain\Rbac\AdminAccountRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

/**
 * Register a user and return [token, id]. Optionally grant them an admin role.
 *
 * @param list<AdminRole> $roles
 * @return array{token: string, id: string}
 */
function adminUser(object $test, string $email, array $roles = []): array
{
    Mail::fake();
    $data = $test->postJson('/api/v1/auth/register', [
        'name' => 'Admin User',
        'email' => $email,
        'password' => 'Password123',
        'password_confirmation' => 'Password123',
    ])->assertCreated()->json('data');

    $id = $data['user']['id'];
    if ($roles !== []) {
        app(AdminAccountRepository::class)->save(AdminAccount::grant($id, $roles, new DateTimeImmutable()));
    }

    return ['token' => $data['tokens']['access_token'], 'id' => $id];
}

it('rejects a non-admin user with 403', function (): void {
    ['token' => $token] = adminUser($this, 'plain@example.com');

    $this->withToken($token)->getJson('/api/v1/admin/accounts')->assertStatus(403);
});

it('lets a super admin manage RBAC', function (): void {
    ['token' => $token] = adminUser($this, 'super@example.com', [AdminRole::SuperAdmin]);

    $this->withToken($token)->getJson('/api/v1/admin/accounts')->assertOk();
    $this->withToken($token)->getJson('/api/v1/admin/permissions')
        ->assertOk()->assertJsonPath('data.roles.0.value', 'super_admin');
});

it('enforces fine-grained permissions per role', function (): void {
    ['token' => $token] = adminUser($this, 'content@example.com', [AdminRole::ContentManager]);

    // Content manager can read the CMS…
    $this->withToken($token)->getJson('/api/v1/admin/cms/pages')->assertOk();
    // …but cannot write configuration.
    $this->withToken($token)->putJson('/api/v1/admin/settings/app.name', ['value' => 'Hacked'])
        ->assertStatus(403);
});
