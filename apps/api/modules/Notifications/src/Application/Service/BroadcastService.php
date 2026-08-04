<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Application\Service;

use DateTimeImmutable;
use EruoFood\Notifications\Domain\Broadcast\AudienceProvider;
use EruoFood\Notifications\Domain\Broadcast\Broadcast;
use EruoFood\Notifications\Domain\Broadcast\BroadcastRepository;
use EruoFood\Notifications\Domain\Enum\NotificationCategory;
use EruoFood\Notifications\Domain\Enum\NotificationChannel;
use EruoFood\Notifications\Domain\Enum\Priority;
use EruoFood\Notifications\Domain\Exception\NotificationsNotFound;
use EruoFood\Shared\Domain\Paginated;

/**
 * Admin broadcast messaging & the campaign manager. Creates a broadcast, resolves
 * its audience segment to recipients, and fans it out as one notification per
 * recipient per channel through the notification engine (so preferences and
 * quiet hours still apply).
 */
final readonly class BroadcastService
{
    public function __construct(
        private BroadcastRepository $broadcasts,
        private AudienceProvider $audience,
        private NotificationService $notifications,
    ) {
    }

    /**
     * @param list<NotificationChannel> $channels
     */
    public function create(
        string $title,
        string $body,
        NotificationCategory $category,
        array $channels,
        string $segment,
        ?DateTimeImmutable $scheduledFor,
    ): Broadcast {
        $broadcast = Broadcast::create(
            $this->broadcasts->nextIdentity(),
            $title,
            $body,
            $category,
            array_map(static fn (NotificationChannel $c): string => $c->value, $channels),
            $segment,
            $scheduledFor,
            new DateTimeImmutable(),
        );
        $this->broadcasts->save($broadcast);

        return $broadcast;
    }

    public function send(string $broadcastId): Broadcast
    {
        $broadcast = $this->broadcasts->findById($broadcastId) ?? throw NotificationsNotFound::of('broadcast', $broadcastId);
        $channels = array_values(array_filter(array_map(
            static fn (string $c): ?NotificationChannel => NotificationChannel::tryFrom($c),
            $broadcast->channels(),
        )));

        $recipients = $this->audience->resolve($broadcast->segment());
        foreach ($recipients as $userId) {
            $this->notifications->notify(
                $userId,
                $broadcast->category(),
                'broadcast',
                ['subject' => $broadcast->title(), 'body' => $broadcast->body()],
                $channels,
                Priority::Normal,
                $broadcast->scheduledFor(),
            );
        }

        $broadcast->markSent(count($recipients));
        $this->broadcasts->save($broadcast);

        return $broadcast;
    }

    /** @return Paginated<Broadcast> */
    public function all(int $page, int $perPage): Paginated
    {
        return $this->broadcasts->all($page, $perPage);
    }
}
