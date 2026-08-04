<?php

declare(strict_types=1);

namespace EruoFood\Identity\Application\Service;

use EruoFood\Identity\Application\DTO\UserProfileView;
use EruoFood\Identity\Application\Port\AuditRecorder;
use EruoFood\Identity\Application\Port\AvatarStorage;
use EruoFood\Identity\Domain\Exception\UserNotFound;
use EruoFood\Identity\Domain\User\UserRepository;
use EruoFood\Identity\Domain\ValueObject\FullName;
use EruoFood\Identity\Domain\ValueObject\PhoneNumber;
use EruoFood\Identity\Domain\ValueObject\UserId;

/** Use cases: view profile, update profile, preferences, avatar, delete account. */
final readonly class ProfileService
{
    public function __construct(
        private UserRepository $users,
        private AvatarStorage $avatars,
        private UserPresenter $presenter,
        private AuditRecorder $audit,
    ) {
    }

    public function getProfile(string $userId): UserProfileView
    {
        return $this->presenter->present($this->load($userId));
    }

    public function updateProfile(string $userId, string $name, ?string $phone): UserProfileView
    {
        $user = $this->load($userId);
        $user->updateProfile(new FullName($name), $phone !== null ? new PhoneNumber($phone) : null);
        $this->users->save($user);
        $this->audit->record('profile.updated', $user->id());

        return $this->presenter->present($user);
    }

    /**
     * @param array<string, mixed> $preferences
     */
    public function updatePreferences(string $userId, array $preferences): UserProfileView
    {
        $user = $this->load($userId);
        $user->updatePreferences($preferences);
        $this->users->save($user);

        return $this->presenter->present($user);
    }

    public function updateAvatar(string $userId, string $contents, string $extension): UserProfileView
    {
        $user = $this->load($userId);

        $previous = $user->avatarPath();
        $path = $this->avatars->store($user->id(), $contents, $extension);
        $user->setAvatar($path);
        $this->users->save($user);

        if ($previous !== null) {
            $this->avatars->delete($previous);
        }
        $this->audit->record('profile.avatar_updated', $user->id());

        return $this->presenter->present($user);
    }

    public function deleteAccount(string $userId): void
    {
        $id = new UserId($userId);
        $this->users->findById($id) ?? throw UserNotFound::forId($userId);
        $this->users->delete($id);
        $this->audit->record('profile.deleted', $id);
    }

    private function load(string $userId): \EruoFood\Identity\Domain\User\User
    {
        return $this->users->findById(new UserId($userId)) ?? throw UserNotFound::forId($userId);
    }
}
