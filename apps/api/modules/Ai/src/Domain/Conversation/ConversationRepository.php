<?php

declare(strict_types=1);

namespace EruoFood\Ai\Domain\Conversation;

use EruoFood\Shared\Domain\Paginated;

/** Persistence port for chat conversations (Repository Pattern). */
interface ConversationRepository
{
    public function nextIdentity(): string;

    public function findById(string $id): ?Conversation;

    /**
     * A user's conversations, most recently updated first.
     *
     * @return Paginated<Conversation>
     */
    public function forUser(string $userId, int $page, int $perPage): Paginated;

    /** Persist the aggregate and its messages. */
    public function save(Conversation $conversation): void;

    public function delete(string $id): void;
}
