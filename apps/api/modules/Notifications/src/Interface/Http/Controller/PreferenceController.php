<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Interface\Http\Controller;

use EruoFood\Notifications\Application\Service\NotificationsPresenter;
use EruoFood\Notifications\Application\Service\PreferenceService;
use EruoFood\Notifications\Domain\Enum\NotificationCategory;
use EruoFood\Notifications\Domain\Enum\NotificationChannel;
use EruoFood\Notifications\Domain\ValueObject\QuietHours;
use EruoFood\Notifications\Interface\Http\Concerns\ResolvesAuthUser;
use EruoFood\Notifications\Interface\Http\Concerns\RespondsWithData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Notification preferences: channels per category, quiet hours, language, frequency. */
final readonly class PreferenceController
{
    use RespondsWithData;
    use ResolvesAuthUser;

    public function __construct(
        private PreferenceService $preferences,
        private NotificationsPresenter $presenter,
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        return $this->data($this->presenter->preference($this->preferences->get($this->currentUserId($request))));
    }

    public function setChannels(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category' => ['required', 'in:account,order,payment,wallet,delivery,promotional,ai,nutrition,admin'],
            'channels' => ['array'],
            'channels.*' => ['in:email,sms,push,in_app,whatsapp,telegram'],
        ]);
        $channels = array_map(
            static fn (string $c): NotificationChannel => NotificationChannel::from($c),
            $data['channels'] ?? [],
        );
        $preference = $this->preferences->setCategoryChannels(
            $this->currentUserId($request),
            NotificationCategory::from((string) $data['category']),
            array_values($channels),
        );

        return $this->data($this->presenter->preference($preference));
    }

    public function setQuietHours(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'start' => ['required', 'date_format:H:i'],
            'end' => ['required', 'date_format:H:i'],
        ]);
        $preference = $this->preferences->setQuietHours(
            $this->currentUserId($request),
            new QuietHours((bool) $data['enabled'], (string) $data['start'], (string) $data['end']),
        );

        return $this->data($this->presenter->preference($preference));
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'language' => ['nullable', 'string', 'max:8'],
            'max_per_day' => ['nullable', 'integer', 'min:0'],
        ]);
        $userId = $this->currentUserId($request);
        if (isset($data['language'])) {
            $this->preferences->setLanguage($userId, (string) $data['language']);
        }
        if (isset($data['max_per_day'])) {
            $this->preferences->setFrequency($userId, (int) $data['max_per_day']);
        }

        return $this->data($this->presenter->preference($this->preferences->get($userId)));
    }
}
