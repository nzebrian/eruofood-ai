<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Application\Port;

use DateTimeImmutable;
use EruoFood\Notifications\Domain\Enum\PresenceStatus;

/** Stores and reads users' real-time presence for connection/heartbeat tracking. */
interface PresenceRepository
{
    public function set(string $userId, PresenceStatus $status, DateTimeImmutable $at): void;

    public function get(string $userId): PresenceStatus;

    /**
     * @param list<string> $userIds
     * @return array<string, string> userId => presence status value
     */
    public function statuses(array $userIds): array;
}
