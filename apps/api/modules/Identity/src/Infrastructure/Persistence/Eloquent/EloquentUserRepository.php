<?php

declare(strict_types=1);

namespace EruoFood\Identity\Infrastructure\Persistence\Eloquent;

use EruoFood\Identity\Domain\Role\Role;
use EruoFood\Identity\Domain\User\TwoFactorSettings;
use EruoFood\Identity\Domain\User\User;
use EruoFood\Identity\Domain\User\UserRepository;
use EruoFood\Identity\Domain\User\UserStatus;
use EruoFood\Identity\Domain\ValueObject\Email;
use EruoFood\Identity\Domain\ValueObject\FullName;
use EruoFood\Identity\Domain\ValueObject\HashedPassword;
use EruoFood\Identity\Domain\ValueObject\PhoneNumber;
use EruoFood\Identity\Domain\ValueObject\UserId;
use EruoFood\Identity\Infrastructure\Persistence\Eloquent\Model\UserModel;
use EruoFood\Shared\Domain\EventBus;
use EruoFood\Shared\Domain\Paginated;
use Illuminate\Support\Str;

/**
 * Eloquent implementation of the UserRepository port. Translates between the
 * persistence model and the domain aggregate, and publishes recorded domain
 * events after a successful save.
 */
final readonly class EloquentUserRepository implements UserRepository
{
    public function __construct(private EventBus $events)
    {
    }

    public function nextIdentity(): UserId
    {
        // Ordered (time-sortable) UUID — index-friendly, per MASTER_PLAN §5.1.
        return new UserId((string) Str::orderedUuid());
    }

    public function findById(UserId $id): ?User
    {
        $model = UserModel::query()->find($id->value());

        return $model !== null ? $this->toDomain($model) : null;
    }

    public function findByEmail(Email $email): ?User
    {
        $model = UserModel::query()->where('email', $email->value)->first();

        return $model !== null ? $this->toDomain($model) : null;
    }

    public function existsByEmail(Email $email): bool
    {
        return UserModel::query()->where('email', $email->value)->exists();
    }

    public function save(User $user): void
    {
        $model = UserModel::query()->find($user->id()->value()) ?? new UserModel();
        $model->id = $user->id()->value();
        $model->name = (string) $user->name();
        $model->email = (string) $user->email();
        $model->phone = $user->phone() !== null ? (string) $user->phone() : null;
        $model->password = $user->password()?->value;
        $model->status = $user->status()->value;
        $model->email_verified_at = $user->emailVerifiedAt();
        $model->avatar_path = $user->avatarPath();
        $model->roles = array_map(static fn (Role $r): string => $r->value, $user->roles());
        $model->preferences = $user->preferences();

        $tf = $user->twoFactor();
        $model->two_factor_secret = $tf->secret;
        $model->two_factor_recovery_codes = $tf->secret !== null ? $tf->recoveryCodes : null;
        $model->two_factor_confirmed_at = $tf->confirmed
            ? ($model->two_factor_confirmed_at ?? now())
            : null;

        $model->save();

        $this->events->publish(...$user->releaseEvents());
    }

    public function delete(UserId $id): void
    {
        UserModel::query()->whereKey($id->value())->delete(); // soft delete
    }

    public function paginate(int $page, int $perPage): Paginated
    {
        $paginator = UserModel::query()
            ->orderByDesc('created_at')
            ->paginate(perPage: $perPage, page: $page);

        $items = array_map(
            fn (UserModel $m): User => $this->toDomain($m),
            $paginator->items(),
        );

        return new Paginated($items, $paginator->total(), $page, $perPage);
    }

    private function toDomain(UserModel $m): User
    {
        return User::reconstitute(
            id: new UserId($m->id),
            name: new FullName($m->name),
            email: new Email($m->email),
            password: $m->password !== null ? new HashedPassword($m->password) : null,
            phone: $m->phone !== null ? new PhoneNumber($m->phone) : null,
            status: UserStatus::from($m->status),
            emailVerifiedAt: $m->email_verified_at?->toDateTimeImmutable(),
            avatarPath: $m->avatar_path,
            roles: array_map(static fn (string $r): Role => Role::from($r), $m->roles ?? []),
            preferences: $m->preferences ?? [],
            twoFactor: new TwoFactorSettings(
                secret: $m->two_factor_secret,
                confirmed: $m->two_factor_confirmed_at !== null,
                recoveryCodes: $m->two_factor_recovery_codes ?? [],
            ),
        );
    }
}
