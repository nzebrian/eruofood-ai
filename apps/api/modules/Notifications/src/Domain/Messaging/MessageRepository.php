<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Domain\Messaging;

use EruoFood\Shared\Domain\Paginated;

/** Persistence port for {@see Message}. */
interface MessageRepository
{
    public function nextIdentity(): string;

    public function findById(string $id): ?Message;

    /** @return Paginated<Message> newest-first page of a conversation's messages */
    public function forConversation(string $conversationId, int $page, int $perPage): Paginated;

    public function unreadCount(string $conversationId, string $userId): int;

    public function save(Message $message): void;
}
