<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Domain\Messaging;

use DateTimeImmutable;
use EruoFood\Notifications\Domain\Enum\MessageType;
use EruoFood\Notifications\Domain\ValueObject\Attachment;

/**
 * A message in a conversation. Text, file or (architecture-ready) voice, with
 * optional attachments and a set of participant ids who have read it (read
 * receipts).
 */
final class Message
{
    /**
     * @param list<Attachment> $attachments
     * @param list<string> $readBy
     */
    private function __construct(
        private readonly string $id,
        private readonly string $conversationId,
        private readonly string $senderId,
        private readonly MessageType $type,
        private readonly string $body,
        private array $attachments,
        private array $readBy,
        private readonly DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * @param list<Attachment> $attachments
     */
    public static function create(
        string $id,
        string $conversationId,
        string $senderId,
        MessageType $type,
        string $body,
        array $attachments,
        DateTimeImmutable $now,
    ): self {
        return new self($id, $conversationId, $senderId, $type, $body, $attachments, [$senderId], $now);
    }

    /**
     * @param list<Attachment> $attachments
     * @param list<string> $readBy
     */
    public static function reconstitute(
        string $id,
        string $conversationId,
        string $senderId,
        MessageType $type,
        string $body,
        array $attachments,
        array $readBy,
        DateTimeImmutable $createdAt,
    ): self {
        return new self($id, $conversationId, $senderId, $type, $body, $attachments, $readBy, $createdAt);
    }

    public function markReadBy(string $userId): void
    {
        if (! in_array($userId, $this->readBy, true)) {
            $this->readBy[] = $userId;
        }
    }

    public function isReadBy(string $userId): bool
    {
        return in_array($userId, $this->readBy, true);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function conversationId(): string
    {
        return $this->conversationId;
    }

    public function senderId(): string
    {
        return $this->senderId;
    }

    public function type(): MessageType
    {
        return $this->type;
    }

    public function body(): string
    {
        return $this->body;
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return $this->attachments;
    }

    /** @return list<string> */
    public function readBy(): array
    {
        return $this->readBy;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
