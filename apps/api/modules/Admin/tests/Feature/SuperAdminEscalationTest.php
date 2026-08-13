<?php

declare(strict_types=1);

use EruoFood\Admin\Domain\Enum\AdminRole;
use EruoFood\Admin\Domain\Rbac\AdminAccount;
use EruoFood\Admin\Domain\Rbac\AdminAccountRepository;
use EruoFood\Identity\Infrastructure\Persistence\Eloquent\Model\UserModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

/**
 * M23 — the coarse Identity `admin` role must not confer back-office authority.
 *
 * `ADMIN_IDENTITY_ADMIN_IS_SUPER` used to default to true, so every holder of an
 * `admin` JWT silently became a SuperAdmin: full finance access, impersonation
 * and RBAC management, regardless of the roles actually granted to them. These
 * tests pin the default shut and prove the nine-role model is really enforced.
 */

/** @return array{token: string, id: string} */
function escalationUser(object $test, string $email, bool $identityAdmin = false): array
{
    Mail::fake();
    $data = $test->postJson('/api/v1/auth/register', [
        'name' => 'Escalation Probe',
        'email' => $email,
        'password' => 'Password123',
        'password_confirmation' => 'Password123',
    ])->assertCreated()->json('data');

    if (! $identityAdmin) {
        return ['token' => $data['tokens']['access_token'], 'id' => $data['user']['id']];
    }

    UserModel::query()->where('email', $email)->update(['roles' => ['admin']]);
    $token = $test->postJson('/api/v1/auth/login', ['email' => $email, 'password' => 'Password123'])
        ->json('data.tokens.access_token');

    return ['token' => $token, 'id' => $data['user']['id']];
}

it('defaults the identity-admin super shortcut to off', function (): void {
    expect(config('admin.identity_admin_is_super'))->toBeFalse();
});

it('refuses back-office access to a user holding only the coarse identity admin role', function (): void {
    ['token' => $token] = escalationUser($this, 'coarse-admin@example.com', identityAdmin: true);

    // RBAC management, impersonation and settings are all SuperAdmin territory.
    $this->withToken($token)->getJson('/api/v1/admin/accounts')->assertStatus(403);
    $this->withToken($token)->getJson('/api/v1/admin/permissions')->assertStatus(403);
    $this->withToken($token)->putJson('/api/v1/admin/settings/app.name', ['value' => 'Hijacked'])
        ->assertStatus(403);
});

it('refuses platform finance data to a user holding only the coarse identity admin role', function (): void {
    ['token' => $token] = escalationUser($this, 'coarse-finance@example.com', identityAdmin: true);

    $this->withToken($token)->getJson('/api/v1/payments/admin/report')->assertStatus(403);
    $this->withToken($token)->getJson('/api/v1/payments/admin/payments')->assertStatus(403);
    $this->withToken($token)->postJson('/api/v1/payments/admin/settlements', [
        'payee_type' => 'vendor',
        'payee_id' => (string) Illuminate\Support\Str::uuid(),
        'gross_minor' => 1_000_000,
    ])->assertStatus(403);
});

it('still admits a genuinely granted super admin', function (): void {
    ['token' => $token, 'id' => $id] = escalationUser($this, 'real-super@example.com');
    app(AdminAccountRepository::class)->save(
        AdminAccount::grant($id, [AdminRole::SuperAdmin], new DateTimeImmutable()),
    );

    $this->withToken($token)->getJson('/api/v1/admin/accounts')->assertOk();
});

it('honours the explicit bootstrap super-admin allow list', function (): void {
    ['token' => $token, 'id' => $id] = escalationUser($this, 'bootstrap-super@example.com');

    // The escape hatch a fresh deployment uses before any account exists.
    config(['admin.bootstrap_super_admins' => [$id]]);

    $this->withToken($token)->getJson('/api/v1/admin/accounts')->assertOk();
});

it('keeps a finance manager inside finance and out of RBAC', function (): void {
    ['token' => $token, 'id' => $id] = escalationUser($this, 'finance-only@example.com');
    app(AdminAccountRepository::class)->save(
        AdminAccount::grant($id, [AdminRole::FinanceManager], new DateTimeImmutable()),
    );

    // Granted: finance.read.
    $this->withToken($token)->getJson('/api/v1/payments/admin/report')->assertOk();

    // Not granted: rbac.manage or config.write — separation of duties holds.
    $this->withToken($token)->getJson('/api/v1/admin/accounts')->assertStatus(403);
    $this->withToken($token)->putJson('/api/v1/admin/settings/app.name', ['value' => 'Nope'])
        ->assertStatus(403);
});

it('keeps a content manager out of platform finance', function (): void {
    ['token' => $token, 'id' => $id] = escalationUser($this, 'content-only@example.com');
    app(AdminAccountRepository::class)->save(
        AdminAccount::grant($id, [AdminRole::ContentManager], new DateTimeImmutable()),
    );

    $this->withToken($token)->getJson('/api/v1/admin/cms/pages')->assertOk();
    $this->withToken($token)->getJson('/api/v1/payments/admin/report')->assertStatus(403);
});

it('rejects an unauthenticated call to the finance routes', function (): void {
    $this->getJson('/api/v1/payments/admin/report')->assertStatus(401);
});
