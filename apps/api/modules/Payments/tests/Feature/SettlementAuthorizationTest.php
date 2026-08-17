<?php

declare(strict_types=1);

use EruoFood\Admin\Domain\Enum\AdminRole;
use EruoFood\Admin\Domain\Rbac\AdminAccount;
use EruoFood\Admin\Domain\Rbac\AdminAccountRepository;
use EruoFood\Admin\Domain\Rbac\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

/**
 * The F5 regression suite.
 *
 * `POST /api/v1/payments/admin/settlements` transfers money to a bank account
 * and was guarded by `permission:finance.read`. Every assertion here exists to
 * stop that from being true again — including the ones that look redundant,
 * because the failure mode is a route quietly moving back into the read group
 * during an unrelated refactor and nothing noticing.
 *
 * @param list<AdminRole> $roles
 * @param list<string> $extraPermissions
 * @return array{token: string, id: string}
 */
function financeUser(object $test, string $email, array $roles = [], array $extraPermissions = []): array
{
    Mail::fake();
    $data = $test->postJson('/api/v1/auth/register', [
        'name' => 'Finance User',
        'email' => $email,
        'password' => 'Password123',
        'password_confirmation' => 'Password123',
    ])->assertCreated()->json('data');

    $id = $data['user']['id'];

    if ($roles !== [] || $extraPermissions !== []) {
        $account = AdminAccount::grant($id, $roles, new DateTimeImmutable());
        foreach ($extraPermissions as $permission) {
            $account->grantPermission($permission);
        }
        app(AdminAccountRepository::class)->save($account);
    }

    return ['token' => $data['tokens']['access_token'], 'id' => $id];
}

/** The body the legacy settle endpoint expects, so a 403 is never a 422 in disguise. */
function settleBody(): array
{
    return [
        'payee_type' => 'vendor',
        'payee_id' => '11111111-1111-4111-8111-111111111111',
        'gross_minor' => 100_000,
        'period_start' => '2027-01-01T00:00:00Z',
        'period_end' => '2027-01-31T23:59:59Z',
    ];
}

it('refuses a settlement to an account holding only finance.read', function (): void {
    // The exact pre-M27 shape of the bug: a principal with the read permission
    // and nothing else. It must be able to look, and unable to touch.
    ['token' => $token] = financeUser($this, 'readonly@example.com', extraPermissions: [Permission::FINANCE_READ]);

    $this->withToken($token)->getJson('/api/v1/payments/admin/settlements')->assertOk();

    $this->withToken($token)
        ->postJson('/api/v1/payments/admin/settlements', settleBody())
        ->assertStatus(403);
});

it('refuses a settlement to customer support', function (): void {
    // CustomerSupport does not hold finance.read today, so this is a guard
    // against a future grant rather than a live hole — which is precisely when
    // an authorization test earns its place.
    ['token' => $token] = financeUser($this, 'support@example.com', [AdminRole::CustomerSupport]);

    $this->withToken($token)
        ->postJson('/api/v1/payments/admin/settlements', settleBody())
        ->assertStatus(403);
});

it('refuses a settlement to a general administrator', function (): void {
    ['token' => $token] = financeUser($this, 'generaladmin@example.com', [AdminRole::Admin]);

    // Sees everything…
    $this->withToken($token)->getJson('/api/v1/payments/admin/report')->assertOk();
    // …moves nothing.
    $this->withToken($token)
        ->postJson('/api/v1/payments/admin/settlements', settleBody())
        ->assertStatus(403);
});

it('allows a settlement to an account holding finance.settle', function (): void {
    ['token' => $token] = financeUser($this, 'settler@example.com', [AdminRole::FinanceManager]);

    // Not asserting a 201: the legacy path's own behaviour is out of scope here.
    // What matters is that authorization no longer refuses it.
    $this->withToken($token)
        ->postJson('/api/v1/payments/admin/settlements', settleBody())
        ->assertStatus(201);
});

it('grants finance manager settle, payout and reconcile but never adjust or reverse', function (): void {
    $granted = Permission::forRole(AdminRole::FinanceManager);

    expect($granted)->toContain(Permission::FINANCE_SETTLE)
        ->and($granted)->toContain(Permission::FINANCE_PAYOUT)
        ->and($granted)->toContain(Permission::FINANCE_RECONCILE)
        ->and($granted)->not->toContain(Permission::FINANCE_ADJUST)
        ->and($granted)->not->toContain(Permission::FINANCE_REVERSE);
});

it('grants no money-moving permission to any role that is not finance manager or super admin', function (): void {
    foreach (AdminRole::cases() as $role) {
        if ($role === AdminRole::SuperAdmin || $role === AdminRole::FinanceManager) {
            continue;
        }

        $granted = Permission::forRole($role);
        foreach (Permission::moneyMoving() as $permission) {
            expect($granted)->not->toContain($permission, "{$role->value} must not hold {$permission}");
        }
    }
});

it('reserves adjust and reverse for super admin alone', function (): void {
    foreach (AdminRole::cases() as $role) {
        if ($role === AdminRole::SuperAdmin) {
            continue;
        }

        expect(Permission::forRole($role))
            ->not->toContain(Permission::FINANCE_ADJUST)
            ->not->toContain(Permission::FINANCE_REVERSE);
    }
});

it('leaves no mutating payments route behind a read-only permission', function (): void {
    // A structural sweep rather than a per-route assertion: a route added later
    // under `permission:finance.read` fails here without anybody remembering to
    // extend this file.
    //
    // Checked against `financeWriting()` rather than `moneyMoving()`, because a
    // route that only changes a reconciliation case still must not be reachable
    // with a read permission — and a sweep that allowed it would be a sweep
    // whose next exception is the one that matters.
    $offenders = [];

    foreach (Route::getRoutes() as $route) {
        $uri = $route->uri();
        if (! str_starts_with($uri, 'api/v1/payments/admin')) {
            continue;
        }

        $methods = array_diff($route->methods(), ['HEAD', 'OPTIONS']);
        $mutating = array_intersect($methods, ['POST', 'PUT', 'PATCH', 'DELETE']) !== [];
        if (! $mutating) {
            continue;
        }

        $permissions = [];
        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'permission:')) {
                $permissions[] = substr($middleware, strlen('permission:'));
            }
        }

        if (array_intersect($permissions, Permission::financeWriting()) === []) {
            $offenders[] = implode('|', $methods).' '.$uri.' → ['.implode(',', $permissions).']';
        }
    }

    expect($offenders)->toBe([]);
});

it('finds mutating admin payments routes to check, so the sweep cannot pass vacuously', function (): void {
    // The sweep above passes trivially if it matches nothing. This asserts the
    // scan actually has subjects — the same guard that caught a `glob('**')`
    // idempotency audit scanning zero files during the cross-cutting work.
    $mutating = 0;

    foreach (Route::getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'api/v1/payments/admin')) {
            continue;
        }
        if (array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']) !== []) {
            $mutating++;
        }
    }

    expect($mutating)->toBeGreaterThan(0);
});
