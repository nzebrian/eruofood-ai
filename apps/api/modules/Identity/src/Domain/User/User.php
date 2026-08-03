<?php

declare(strict_types=1);

namespace EruoFood\Identity\Domain\User;

use DateTimeImmutable;
use EruoFood\Identity\Domain\Event\EmailVerified;
use EruoFood\Identity\Domain\Event\PasswordChanged;
use EruoFood\Identity\Domain\Event\TwoFactorEnabled;
use EruoFood\Identity\Domain\Event\UserRegistered;
use EruoFood\Identity\Domain\Role\Permission;
use EruoFood\Identity\Domain\Role\Role;
use EruoFood\Identity\Domain\ValueObject\Email;
use EruoFood\Identity\Domain\ValueObject\FullName;
use EruoFood\Identity\Domain\ValueObject\HashedPassword;
use EruoFood\Identity\Domain\ValueObject\PhoneNumber;
use EruoFood\Identity\Domain\ValueObject\UserId;
use EruoFood\Shared\Domain\AggregateRoot;

/**
 * User aggregate root — the consistency boundary for a person's identity,
 * credentials, roles, profile, and 2FA state. All state changes go through
 * behaviour methods that enforce invariants and record domain events.
 */
final class User extends AggregateRoot
{
    /**
     * @param list<Role> $roles
     * @param array<string, mixed> $preferences
     */
    private function __construct(
        private readonly UserId $id,
        private FullName $name,
        private Email $email,
        private ?HashedPassword $password,
        private ?PhoneNumber $phone,
        private UserStatus $status,
        private ?DateTimeImmutable $emailVerifiedAt,
        private ?string $avatarPath,
        private array $roles,
        private array $preferences,
        private TwoFactorSettings $twoFactor,
    ) {
    }

    /**
     * Register a brand-new user. `password` is null for social-only accounts.
     */
    public static function register(
        UserId $id,
        FullName $name,
        Email $email,
        ?HashedPassword $password,
    ): self {
        $user = new self(
            id: $id,
            name: $name,
            email: $email,
            password: $password,
            phone: null,
            status: UserStatus::Active,
            emailVerifiedAt: null,
            avatarPath: null,
            roles: [Role::User],
            preferences: [],
            twoFactor: TwoFactorSettings::disabled(),
        );

        $user->recordThat(new UserRegistered($id, $email));

        return $user;
    }

    /**
     * Rebuild an aggregate from persistence (no events recorded).
     *
     * @param list<Role> $roles
     * @param array<string, mixed> $preferences
     */
    public static function reconstitute(
        UserId $id,
        FullName $name,
        Email $email,
        ?HashedPassword $password,
        ?PhoneNumber $phone,
        UserStatus $status,
        ?DateTimeImmutable $emailVerifiedAt,
        ?string $avatarPath,
        array $roles,
        array $preferences,
        TwoFactorSettings $twoFactor,
    ): self {
        return new self(
            $id,
            $name,
            $email,
            $password,
            $phone,
            $status,
            $emailVerifiedAt,
            $avatarPath,
            $roles,
            $preferences,
            $twoFactor,
        );
    }

    // ---- Email verification -------------------------------------------------

    public function verifyEmail(): void
    {
        if ($this->emailVerifiedAt !== null) {
            return; // idempotent
        }

        $this->emailVerifiedAt = new DateTimeImmutable();
        $this->recordThat(new EmailVerified($this->id));
    }

    public function hasVerifiedEmail(): bool
    {
        return $this->emailVerifiedAt !== null;
    }

    // ---- Credentials --------------------------------------------------------

    public function changePassword(HashedPassword $password): void
    {
        $this->password = $password;
        $this->recordThat(new PasswordChanged($this->id));
    }

    public function hasPassword(): bool
    {
        return $this->password !== null;
    }

    // ---- Profile ------------------------------------------------------------

    public function updateProfile(FullName $name, ?PhoneNumber $phone): void
    {
        $this->name = $name;
        $this->phone = $phone;
    }

    public function setAvatar(?string $path): void
    {
        $this->avatarPath = $path;
    }

    /**
     * @param array<string, mixed> $preferences
     */
    public function updatePreferences(array $preferences): void
    {
        $this->preferences = $preferences;
    }

    // ---- Roles & permissions ------------------------------------------------

    public function assignRole(Role $role): void
    {
        if (! $this->hasRole($role)) {
            $this->roles[] = $role;
        }
    }

    public function revokeRole(Role $role): void
    {
        $this->roles = array_values(array_filter(
            $this->roles,
            static fn (Role $existing): bool => $existing !== $role,
        ));
    }

    public function hasRole(Role $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    public function hasPermission(Permission $permission): bool
    {
        foreach ($this->roles as $role) {
            if ($role->grants($permission)) {
                return true;
            }
        }

        return false;
    }

    // ---- Status -------------------------------------------------------------

    public function suspend(): void
    {
        $this->status = UserStatus::Suspended;
    }

    public function activate(): void
    {
        $this->status = UserStatus::Active;
    }

    // ---- Two-factor authentication -----------------------------------------

    /**
     * Begin enrolment: store the secret + recovery codes, pending confirmation.
     *
     * @param list<string> $recoveryCodes
     */
    public function enableTwoFactor(string $secret, array $recoveryCodes): void
    {
        $this->twoFactor = new TwoFactorSettings($secret, false, $recoveryCodes);
    }

    /** Confirm enrolment once the user proves possession with a valid code. */
    public function confirmTwoFactor(): void
    {
        if ($this->twoFactor->secret === null) {
            return;
        }

        $this->twoFactor = new TwoFactorSettings(
            $this->twoFactor->secret,
            true,
            $this->twoFactor->recoveryCodes,
        );

        $this->recordThat(new TwoFactorEnabled($this->id));
    }

    public function disableTwoFactor(): void
    {
        $this->twoFactor = TwoFactorSettings::disabled();
    }

    // ---- Accessors (for persistence + read models) -------------------------

    public function id(): UserId
    {
        return $this->id;
    }

    public function name(): FullName
    {
        return $this->name;
    }

    public function email(): Email
    {
        return $this->email;
    }

    public function password(): ?HashedPassword
    {
        return $this->password;
    }

    public function phone(): ?PhoneNumber
    {
        return $this->phone;
    }

    public function status(): UserStatus
    {
        return $this->status;
    }

    public function emailVerifiedAt(): ?DateTimeImmutable
    {
        return $this->emailVerifiedAt;
    }

    public function avatarPath(): ?string
    {
        return $this->avatarPath;
    }

    /** @return list<Role> */
    public function roles(): array
    {
        return $this->roles;
    }

    /** @return array<string, mixed> */
    public function preferences(): array
    {
        return $this->preferences;
    }

    public function twoFactor(): TwoFactorSettings
    {
        return $this->twoFactor;
    }
}
