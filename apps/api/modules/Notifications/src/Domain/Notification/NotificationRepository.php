<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Domain\Notification;

use EruoFood\Notifications\Domain\Enum\NotificationChannel;
use EruoFood\Shared\Domain\Paginated;

/** Persistence port for the {@see Notification} aggregate. */
interface NotificationRepository
{
    public function nextIdentity(): string;

    public function findById(string $id): ?Notification;

    /**
     * The in-app notification centre for a user (optionally unread only).
     *
     * @return Paginated<Notification>
     */
    public function forUser(string $userId, bool $unreadOnly, int $page, int $perPage): Paginated;

    public function unreadCount(string $userId): int;

    /**
     * Notifications ready to be dispatched now (queued/scheduled + due), for the
     * queue worker. Optionally filtered to a channel.
     *
     * @return list<Notification>
     */
    public function dueForDispatch(NotificationChannel $channel, int $limit): array;

    /** @return list<Notification> failed notifications eligible for retry */
    public function retryable(int $maxAttempts, int $limit): array;

    public function markAllRead(string $userId): void;

    public function save(Notification $notification): void;
}
