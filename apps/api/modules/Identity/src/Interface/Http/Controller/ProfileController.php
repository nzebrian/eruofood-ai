<?php

declare(strict_types=1);

namespace EruoFood\Identity\Interface\Http\Controller;

use EruoFood\Identity\Application\Service\PasswordService;
use EruoFood\Identity\Application\Service\ProfileService;
use EruoFood\Identity\Interface\Http\Concerns\ResolvesAuthUser;
use EruoFood\Identity\Interface\Http\Request\ChangePasswordRequest;
use EruoFood\Identity\Interface\Http\Request\UpdateProfileRequest;
use EruoFood\Identity\Interface\Http\Resource\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Authenticated user's own account: profile, password, preferences, avatar. */
final readonly class ProfileController
{
    use ResolvesAuthUser;

    public function __construct(
        private ProfileService $profiles,
        private PasswordService $passwords,
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        $view = $this->profiles->getProfile($this->currentUserId($request));

        return UserResource::make($view)->response();
    }

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $view = $this->profiles->updateProfile(
            userId: $this->currentUserId($request),
            name: (string) $request->string('name'),
            phone: $request->filled('phone') ? (string) $request->string('phone') : null,
        );

        return UserResource::make($view)->response();
    }

    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $this->passwords->change(
            userId: $this->currentUserId($request),
            currentPassword: (string) $request->string('current_password'),
            newPassword: (string) $request->string('password'),
        );

        return new JsonResponse(['data' => ['message' => 'Password changed.']]);
    }

    public function preferences(Request $request): JsonResponse
    {
        $validated = $request->validate(['preferences' => ['required', 'array']]);

        $view = $this->profiles->updatePreferences(
            $this->currentUserId($request),
            $validated['preferences'],
        );

        return UserResource::make($view)->response();
    }

    public function avatar(Request $request): JsonResponse
    {
        $request->validate([
            'avatar' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $file = $request->file('avatar');

        $view = $this->profiles->updateAvatar(
            userId: $this->currentUserId($request),
            contents: (string) $file->get(),
            extension: $file->extension() ?: 'jpg',
        );

        return UserResource::make($view)->response();
    }

    public function destroy(Request $request): JsonResponse
    {
        $this->profiles->deleteAccount($this->currentUserId($request));

        return new JsonResponse(null, 204);
    }
}
