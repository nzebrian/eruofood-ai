<?php

declare(strict_types=1);

namespace EruoFood\Nutrition\Application\Service;

use EruoFood\Nutrition\Application\Input\HealthProfileInput;
use EruoFood\Nutrition\Domain\Event\HealthProfileUpdated;
use EruoFood\Nutrition\Domain\Exception\ProfileNotConfigured;
use EruoFood\Nutrition\Domain\Health\HealthProfile;
use EruoFood\Nutrition\Domain\Health\HealthProfileRepository;
use EruoFood\Shared\Domain\Clock;
use EruoFood\Shared\Domain\EventBus;

/** Manages the per-user health profile (create/update, read). */
final readonly class HealthProfileService
{
    public function __construct(
        private HealthProfileRepository $profiles,
        private Clock $clock,
        private EventBus $events,
    ) {
    }

    public function get(string $userId): ?HealthProfile
    {
        return $this->profiles->findByUser($userId);
    }

    /** @throws ProfileNotConfigured */
    public function getOrFail(string $userId): HealthProfile
    {
        return $this->profiles->findByUser($userId) ?? throw new ProfileNotConfigured();
    }

    /** Create or update the caller's profile. */
    public function save(string $userId, HealthProfileInput $input): HealthProfile
    {
        $now = $this->clock->now();
        $existing = $this->profiles->findByUser($userId);

        if ($existing !== null) {
            $existing->update(
                $input->weightKg,
                $input->heightCm,
                $input->age,
                $input->gender,
                $input->activityLevel,
                $input->goal,
                $input->dietaryPreferences,
                $input->allergies,
                $input->medicalRestrictions,
                $now,
            );
            $profile = $existing;
        } else {
            $profile = HealthProfile::create(
                $userId,
                $input->weightKg,
                $input->heightCm,
                $input->age,
                $input->gender,
                $input->activityLevel,
                $input->goal,
                $input->dietaryPreferences,
                $input->allergies,
                $input->medicalRestrictions,
                $now,
            );
        }

        $this->profiles->save($profile);
        $this->events->publish(new HealthProfileUpdated($userId));

        return $profile;
    }
}
