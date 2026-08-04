<?php

declare(strict_types=1);

use EruoFood\Identity\Domain\Event\EmailVerified;
use EruoFood\Identity\Domain\Event\TwoFactorEnabled;
use EruoFood\Identity\Domain\Event\UserRegistered;
use EruoFood\Identity\Domain\Role\Permission;
use EruoFood\Identity\Domain\Role\Role;
use EruoFood\Identity\Domain\User\User;
use EruoFood\Identity\Domain\ValueObject\Email;
use EruoFood\Identity\Domain\ValueObject\FullName;
use EruoFood\Identity\Domain\ValueObject\HashedPassword;
use EruoFood\Identity\Domain\ValueObject\UserId;

function makeUser(): User
{
    return User::register(
        id: new UserId('0193f8a0-1111-7abc-8def-0123456789ab'),
        name: new FullName('Ada Lovelace'),
        email: new Email('ada@example.com'),
        password: new HashedPassword(str_repeat('x', 40)),
    );
}

it('records a UserRegistered event on registration', function (): void {
    $events = makeUser()->releaseEvents();

    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(UserRegistered::class);
});

it('defaults new users to the User role', function (): void {
    $user = makeUser();

    expect($user->hasRole(Role::User))->toBeTrue()
        ->and($user->hasRole(Role::Admin))->toBeFalse()
        ->and($user->hasPermission(Permission::ManageOwnProfile))->toBeTrue()
        ->and($user->hasPermission(Permission::ManageUsers))->toBeFalse();
});

it('verifies email idempotently and records the event once', function (): void {
    $user = makeUser();
    $user->releaseEvents();

    $user->verifyEmail();
    $user->verifyEmail();

    expect($user->hasVerifiedEmail())->toBeTrue()
        ->and($user->releaseEvents())->toHaveCount(1)
        ->and($user->releaseEvents())->toBeEmpty();
});

it('grants admin permissions when the admin role is assigned', function (): void {
    $user = makeUser();
    $user->assignRole(Role::Admin);

    expect($user->hasPermission(Permission::ManageUsers))->toBeTrue()
        ->and($user->hasPermission(Permission::ManageRoles))->toBeTrue();

    $user->revokeRole(Role::Admin);
    expect($user->hasPermission(Permission::ManageUsers))->toBeFalse();
});

it('transitions two-factor through enable, confirm, and disable', function (): void {
    $user = makeUser();
    $user->releaseEvents();

    $user->enableTwoFactor('SECRET', ['code-1', 'code-2']);
    expect($user->twoFactor()->isPending())->toBeTrue()
        ->and($user->twoFactor()->isEnabled())->toBeFalse();

    $user->confirmTwoFactor();
    expect($user->twoFactor()->isEnabled())->toBeTrue();

    $events = $user->releaseEvents();
    expect($events[0])->toBeInstanceOf(TwoFactorEnabled::class);

    $user->disableTwoFactor();
    expect($user->twoFactor()->isEnabled())->toBeFalse();
});

it('records EmailVerified via the released events', function (): void {
    $user = makeUser();
    $user->releaseEvents();
    $user->verifyEmail();

    expect($user->releaseEvents()[0])->toBeInstanceOf(EmailVerified::class);
});
