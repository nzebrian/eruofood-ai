<?php

declare(strict_types=1);

namespace EruoFood\Ai\Application\Service;

use EruoFood\Ai\Domain\Conversation\Conversation;
use EruoFood\Ai\Domain\Conversation\ConversationRepository;
use EruoFood\Ai\Domain\Exception\ConversationNotFound;
use EruoFood\Shared\Domain\Paginated;

/**
 * Manages the AI chat history: listing a user's threads and fetching/renaming/
 * deleting one, always enforcing that the caller owns the conversation.
 */
final readonly class ConversationService
{
    public function __construct(private ConversationRepository $conversations)
    {
    }

    /**
     * @return Paginated<Conversation>
     */
    public function list(string $userId, int $page, int $perPage): Paginated
    {
        return $this->conversations->forUser($userId, max(1, $page), min(50, max(1, $perPage)));
    }

    /** @throws ConversationNotFound */
    public function get(string $userId, string $id): Conversation
    {
        return $this->ownedOrFail($userId, $id);
    }

    /** @throws ConversationNotFound */
    public function delete(string $userId, string $id): void
    {
        $this->ownedOrFail($userId, $id);
        $this->conversations->delete($id);
    }

    /** @throws ConversationNotFound */
    public function rename(string $userId, string $id, string $title): Conversation
    {
        $conversation = $this->ownedOrFail($userId, $id);
        $conversation->rename($title);
        $this->conversations->save($conversation);

        return $conversation;
    }

    private function ownedOrFail(string $userId, string $id): Conversation
    {
        $conversation = $this->conversations->findById($id);
        if ($conversation === null || $conversation->userId() !== $userId) {
            throw ConversationNotFound::withId($id);
        }

        return $conversation;
    }
}
