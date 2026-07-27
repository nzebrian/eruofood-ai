<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Domain\Messaging;

/** Persistence port for {@see Conversation}. */
interface ConversationRepository
{
    public function nextIdentity(): string;

    public function findById(string $id): ?Conversation;

    /** @return list<Conversation> the user's conversations, most recent first */
    public function forUser(string $userId): array;

    public function save(Conversation $conversation): void;
}
