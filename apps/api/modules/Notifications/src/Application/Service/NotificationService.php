<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Application\Service;

use DateTimeImmutable;
use EruoFood\Notifications\Application\DTO\DeliveryOutcome;
use EruoFood\Notifications\Domain\Enum\NotificationCategory;
use EruoFood\Notifications\Domain\Enum\NotificationChannel;
use EruoFood\Notifications\Domain\Enum\NotificationClass;
use EruoFood\Notifications\Domain\Enum\Priority;
use EruoFood\Notifications\Domain\Exception\NotificationsNotAuthorized;
use EruoFood\Notifications\Domain\Exception\NotificationsNotFound;
use EruoFood\Notifications\Domain\Notification\Notification;
use EruoFood\Notifications\Domain\Notification\NotificationRepository;
use EruoFood\Notifications\Domain\Preference\NotificationPreference;
use EruoFood\Notifications\Domain\Preference\NotificationPreferenceRepository;
use EruoFood\Notifications\Domain\Template\NotificationTemplateRepository;
use EruoFood\Notifications\Domain\ValueObject\QuietHours;
use EruoFood\Notifications\Domain\ValueObject\RenderedContent;
use EruoFood\Shared\Domain\EventBus;
use EruoFood\Shared\Domain\Paginated;
use Throwable;

/**
 * The Notification Engine: turns a request-to-notify into one queued, rendered
 * notification per eligible channel, honouring the user's per-category channel
 * preferences and quiet hours, then dispatches each through the channel
 * dispatcher and pushes a real-time event to the user. Also owns the retry loop,
 * scheduled dispatch and the in-app read state.
 *
 * Business modules never call this directly — the {@see EventTranslator} invokes
 * it from published domain events.
 */
final readonly class NotificationService
{
    public function __construct(
        private NotificationRepository $notifications,
        private NotificationPreferenceRepository $preferences,
        private NotificationTemplateRepository $templates,
        private ChannelDispatcher $dispatcher,
        private \EruoFood\Notifications\Application\Port\RealtimeBroadcaster $realtime,
        private EventBus $events,
        private string $defaultLanguage,
        private int $maxAttempts,
        private QuietHours $defaultQuietHours,
    ) {
    }

    /**
     * Create + dispatch a notification across the given channels (filtered by the
     * user's preferences and the enabled channel set).
     *
     * @param array<string, mixed> $data
     * @param list<NotificationChannel> $channels
     * @return list<Notification>
     */
    public function notify(
        string $userId,
        NotificationCategory $category,
        string $templateKey,
        array $data,
        array $channels,
        Priority $priority = Priority::Normal,
        ?DateTimeImmutable $scheduledFor = null,
        ?string $correlationId = null,
    ): array {
        $preference = $this->preferences->forUser($userId)
            ?? NotificationPreference::defaults($userId, $this->defaultLanguage, $this->defaultQuietHours);

        $class = NotificationClass::forCategory($category);

        // Consent first. Marketing needs a positive opt-in; security messages
        // are never suppressed, because somebody who has silenced their account
        // alerts has not exercised a preference, they have lost their only
        // warning that the account is being tampered with.
        if (! $preference->permits($class)) {
            return [];
        }

        $now = new DateTimeImmutable();
        $created = [];

        foreach ($channels as $channel) {
            if (! $this->dispatcher->supports($channel)) {
                continue;
            }
            if (! $preference->allows($category, $channel)) {
                continue;
            }

            $schedule = $this->resolveSchedule($category, $priority, $preference->quietHours(), $scheduledFor, $now);
            $content = $this->render($templateKey, $channel, $preference->language(), $data);

            $notification = Notification::create(
                $this->notifications->nextIdentity(),
                $userId,
                $category,
                $channel,
                $templateKey,
                $data,
                $content,
                $priority,
                $schedule,
                $now,
                $correlationId,
            );
            $this->notifications->save($notification);

            if ($notification->isDue($now)) {
                $this->dispatch($notification);
            }
            $created[] = $notification;
        }

        return $created;
    }

    /**
     * Deliver a single notification through its channel and record the outcome.
     *
     * Nothing here is allowed to throw. This runs from a domain-event listener,
     * so an exception escaping would propagate into whatever business operation
     * published the event — a rider's verification would fail because an SMTP
     * host was briefly unreachable. A failed send is recorded as a failed send.
     */
    public function dispatch(Notification $notification): void
    {
        $now = new DateTimeImmutable();
        $notification->queue($now);

        try {
            $outcome = $this->dispatcher->send($notification);
        } catch (Throwable $e) {
            // A sender that throws rather than returning a failure is itself a
            // defect, but it must not become the caller's problem.
            $outcome = DeliveryOutcome::failed($e::class);
        }

        if ($outcome->success) {
            $notification->markSent($now, $outcome->providerMessageId);
            $notification->markDelivered(new DateTimeImmutable());
            try {
                $this->realtime->broadcast('user.'.$notification->userId(), 'notification.created', [
                    'id' => $notification->id(),
                    'category' => $notification->category()->value,
                    'channel' => $notification->channel()->value,
                    'subject' => $notification->content()->subject,
                    'body' => $notification->content()->body,
                ]);
            } catch (Throwable) {
                // A real-time push is a convenience; losing it must not turn a
                // delivered notification into a failed one.
            }
        } else {
            $notification->markFailed($outcome->detail ?? 'Delivery failed.', $now, $outcome->retryable);
        }

        $this->notifications->save($notification);
        foreach ($notification->releaseEvents() as $event) {
            $this->events->publish($event);
        }
    }

    /** Dispatch scheduled notifications that are now due (queue worker entry point). */
    public function dispatchDue(NotificationChannel $channel, int $limit = 100): int
    {
        $count = 0;
        foreach ($this->notifications->dueForDispatch($channel, $limit) as $notification) {
            $this->dispatch($notification);
            $count++;
        }

        return $count;
    }

    /** Retry failed notifications under the attempt cap (retry mechanism). */
    public function retryFailed(int $limit = 100): int
    {
        $count = 0;
        foreach ($this->notifications->retryable($this->maxAttempts, $limit) as $notification) {
            if ($notification->canRetry($this->maxAttempts)) {
                $this->dispatch($notification);
                $count++;
            }
        }

        return $count;
    }

    /** @return Paginated<Notification> */
    public function centre(string $userId, bool $unreadOnly, int $page, int $perPage): Paginated
    {
        return $this->notifications->forUser($userId, $unreadOnly, $page, $perPage);
    }

    public function unreadCount(string $userId): int
    {
        return $this->notifications->unreadCount($userId);
    }

    public function markRead(string $notificationId, string $userId): Notification
    {
        $notification = $this->notifications->findById($notificationId) ?? throw NotificationsNotFound::of('notification', $notificationId);
        if (! $notification->isForUser($userId)) {
            throw new NotificationsNotAuthorized();
        }
        $notification->markRead(new DateTimeImmutable());
        $this->notifications->save($notification);

        return $notification;
    }

    public function markAllRead(string $userId): void
    {
        $this->notifications->markAllRead($userId);
    }

    private function resolveSchedule(
        NotificationCategory $category,
        Priority $priority,
        QuietHours $quietHours,
        ?DateTimeImmutable $scheduledFor,
        DateTimeImmutable $now,
    ): ?DateTimeImmutable {
        if ($scheduledFor !== null) {
            return $scheduledFor;
        }
        // Defer quiet-hours-respecting categories out of the window (unless high priority).
        if ($priority !== Priority::High && $category->respectsQuietHours() && $quietHours->isWithin($now)) {
            [$h, $m] = array_pad(explode(':', $quietHours->end), 2, '0');
            $end = $now->setTime((int) $h, (int) $m);

            return $end <= $now ? $end->modify('+1 day') : $end;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function render(string $templateKey, NotificationChannel $channel, string $locale, array $data): RenderedContent
    {
        $template = $this->templates->find($templateKey, $channel, $locale)
            ?? $this->templates->find($templateKey, $channel, $this->defaultLanguage);
        if ($template !== null) {
            return $template->render($data);
        }

        // Fallback: use explicit subject/body in the payload, else a humble default.
        $subject = isset($data['subject']) ? (string) $data['subject'] : ucwords(str_replace(['_', '.'], ' ', $templateKey));
        $body = isset($data['body']) ? (string) $data['body'] : ($data['message'] ?? 'You have a new notification.');

        return new RenderedContent($subject, (string) $body);
    }
}
