<?php

declare(strict_types=1);

use EruoFood\Admin\Domain\Enum\AccountStatus;
use EruoFood\Admin\Domain\Enum\AdminRole;
use EruoFood\Admin\Domain\Rbac\AdminAccount;
use EruoFood\Admin\Domain\Rbac\Impersonation;
use EruoFood\Admin\Domain\Rbac\Permission;

function adminAccount(array $roles): AdminAccount
{
    return AdminAccount::grant('user-1', $roles, new DateTimeImmutable('2026-07-01T00:00:00Z'));
}

it('gives a super admin every permission', function (): void {
    $account = adminAccount([AdminRole::SuperAdmin]);
    expect($account->isSuper())->toBeTrue()
        ->and($account->permissions())->toEqual(Permission::all())
        ->and($account->can(Permission::CONFIG_WRITE))->toBeTrue();
});

it('unions role permissions with extra grants', function (): void {
    $account = adminAccount([AdminRole::ContentManager]);
    expect($account->can(Permission::CONTENT_MANAGE))->toBeTrue()
        ->and($account->can(Permission::CONFIG_WRITE))->toBeFalse();

    $account->grantPermission(Permission::CONFIG_WRITE);
    expect($account->can(Permission::CONFIG_WRITE))->toBeTrue();

    $account->revokePermission(Permission::CONFIG_WRITE);
    expect($account->can(Permission::CONFIG_WRITE))->toBeFalse();
});

it('denies everything while suspended', function (): void {
    $account = adminAccount([AdminRole::Admin]);
    expect($account->can(Permission::USERS_MODERATE))->toBeTrue();

    $account->suspend();
    expect($account->status())->toBe(AccountStatus::Suspended)
        ->and($account->can(Permission::USERS_MODERATE))->toBeFalse();

    $account->activate();
    expect($account->can(Permission::USERS_MODERATE))->toBeTrue();
});

it('maps every role to a permission subset and groups by prefix', function (): void {
    foreach (AdminRole::cases() as $role) {
        expect(Permission::forRole($role))->each->toBeIn(Permission::all());
    }
    expect(Permission::groups())->toHaveKey('rbac')
        ->and(Permission::groups()['config'])->toContain(Permission::CONFIG_READ, Permission::CONFIG_WRITE);
});

it('opens and closes an impersonation exactly once', function (): void {
    $imp = Impersonation::start('imp-1', 'admin-1', 'target-1', 'debugging', new DateTimeImmutable());
    expect($imp->isActive())->toBeTrue();

    $first = new DateTimeImmutable('2026-07-01T10:00:00Z');
    $imp->end($first);
    $imp->end(new DateTimeImmutable('2026-07-01T11:00:00Z')); // idempotent
    expect($imp->isActive())->toBeFalse()
        ->and($imp->endedAt())->toEqual($first);
});
