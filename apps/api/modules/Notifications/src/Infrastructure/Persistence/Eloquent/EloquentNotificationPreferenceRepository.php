<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Infrastructure\Persistence\Eloquent;

use EruoFood\Notifications\Domain\Preference\NotificationPreference;
use EruoFood\Notifications\Domain\Preference\NotificationPreferenceRepository;
use EruoFood\Notifications\Domain\ValueObject\QuietHours;
use EruoFood\Notifications\Infrastructure\Persistence\Eloquent\Model\NotificationPreferenceModel;

final class EloquentNotificationPreferenceRepository implements NotificationPreferenceRepository
{
    public function forUser(string $userId): ?NotificationPreference
    {
        $m = NotificationPreferenceModel::query()->find($userId);
        if ($m === null) {
            return null;
        }

        /** @var array<string, list<string>> $channels */
        $channels = $m->channels_by_category ?? [];

        return NotificationPreference::reconstitute(
            userId: $m->user_id,
            channelsByCategory: $channels,
            quietHours: QuietHours::fromArray($m->quiet_hours ?? []),
            language: $m->language,
            maxPerDay: (int) $m->max_per_day,
        );
    }

    public function save(NotificationPreference $preference): void
    {
        $model = NotificationPreferenceModel::query()->find($preference->userId()) ?? new NotificationPreferenceModel();
        $model->user_id = $preference->userId();
        $model->channels_by_category = $preference->channelsByCategory();
        $model->quiet_hours = $preference->quietHours()->toArray();
        $model->language = $preference->language();
        $model->max_per_day = $preference->maxPerDay();
        $model->save();
    }
}
