<?php

declare(strict_types=1);

namespace EruoFood\Identity\Application\Service;

use EruoFood\Identity\Application\DTO\UserProfileView;
use EruoFood\Identity\Application\Port\AvatarStorage;
use EruoFood\Identity\Domain\User\User;

/** Maps a User aggregate to its public view, resolving the avatar URL. */
final readonly class UserPresenter
{
    public function __construct(private AvatarStorage $avatars)
    {
    }

    public function present(User $user): UserProfileView
    {
        $avatarUrl = $user->avatarPath() !== null
            ? $this->avatars->url($user->avatarPath())
            : null;

        return UserProfileView::fromUser($user, $avatarUrl);
    }
}
