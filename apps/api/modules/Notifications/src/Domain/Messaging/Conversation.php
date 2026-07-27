<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Domain\Messaging;

use DateTimeImmutable;
use EruoFood\Notifications\Domain\Enum\ConversationType;
use EruoFood\Notifications\Domain\Exception\NotificationsNotAuthorized;

/**
 * A conversation between participants — customer ↔ restaurant/vendor/rider,
 * admin ↔ user, or a group announcement channel. The aggregate root guards
 * participation (only members may read/post) and tracks the last-activity time
 * for inbox ordering. An optional subject and a soft reference (e.g. an order id)
 * give the thread context.
 */
final class Conversation
{
    /**
     * @param list<string> $participantIds
     */
    private function __construct(
        private readonly string $id,
        private readonly ConversationType $type,
        private array $participantIds,
        private readonly ?string $subject,
        private readonly ?string $contextRef,
        private DateTimeImmutable $lastMessageAt,
        private readonly DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * @param list<string> $participantIds
     */
    public static function open(
        string $id,
        ConversationType $type,
        array $participantIds,
        ?string $subject,
        ?string $contextRef,
        DateTimeImmutable $now,
    ): self {
        return new self($id, $type, array_values(array_unique($participantIds)), $subject, $contextRef, $now, $now);
    }

    /**
     * @param list<string> $participantIds
     */
    public static function reconstitute(
        string $id,
        ConversationType $type,
        array $participantIds,
        ?string $subject,
        ?string $contextRef,
        DateTimeImmutable $lastMessageAt,
        DateTimeImmutable $createdAt,
    ): self {
        return new self($id, $type, array_values($participantIds), $subject, $contextRef, $lastMessageAt, $createdAt);
    }

    public function hasParticipant(string $userId): bool
    {
        return in_array($userId, $this->participantIds, true);
    }

    public function assertParticipant(string $userId): void
    {
        if (! $this->hasParticipant($userId)) {
            throw new NotificationsNotAuthorized('You are not a participant in this conversation.');
        }
    }

    public function addParticipant(string $userId): void
    {
        if (! $this->hasParticipant($userId)) {
            $this->participantIds[] = $userId;
        }
    }

    public function touch(DateTimeImmutable $at): void
    {
        $this->lastMessageAt = $at;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function type(): ConversationType
    {
        return $this->type;
    }

    /** @return list<string> */
    public function participantIds(): array
    {
        return $this->participantIds;
    }

    public function subject(): ?string
    {
        return $this->subject;
    }

    public function contextRef(): ?string
    {
        return $this->contextRef;
    }

    public function lastMessageAt(): DateTimeImmutable
    {
        return $this->lastMessageAt;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
