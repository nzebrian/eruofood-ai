<?php

declare(strict_types=1);

namespace EruoFood\Ai\Domain\Conversation;

use DateTimeImmutable;
use EruoFood\Ai\Domain\Enum\AiFeature;
use EruoFood\Ai\Domain\Enum\MessageRole;
use EruoFood\Ai\Domain\ValueObject\AiMessage;
use EruoFood\Shared\Domain\AggregateRoot;

/**
 * A user's chat thread with the Smart Cooking Assistant (chat history).
 *
 * The aggregate is the consistency boundary for a conversation and its ordered
 * messages: turns are only ever appended, and the assistant reply is recorded
 * against the same thread as the user prompt that produced it.
 */
final class Conversation extends AggregateRoot
{
    /**
     * @param list<ConversationMessage> $messages
     */
    private function __construct(
        private readonly string $id,
        private readonly string $userId,
        private readonly AiFeature $feature,
        private string $title,
        private array $messages,
        private readonly DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt,
    ) {
    }

    public static function start(
        string $id,
        string $userId,
        string $title,
        DateTimeImmutable $now,
        AiFeature $feature = AiFeature::CookingAssistant,
    ): self {
        return new self($id, $userId, $feature, $title, [], $now, $now);
    }

    /**
     * @param list<ConversationMessage> $messages
     */
    public static function reconstitute(
        string $id,
        string $userId,
        AiFeature $feature,
        string $title,
        array $messages,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
    ): self {
        return new self($id, $userId, $feature, $title, array_values($messages), $createdAt, $updatedAt);
    }

    public function addMessage(MessageRole $role, string $content, DateTimeImmutable $at): void
    {
        $this->messages[] = new ConversationMessage($role, $content, $at);
        $this->updatedAt = $at;
    }

    public function rename(string $title): void
    {
        $this->title = $title;
    }

    /**
     * The message history mapped to the provider's message value objects, so the
     * assistant answers with full context of the thread.
     *
     * @return list<AiMessage>
     */
    public function toAiMessages(): array
    {
        return array_map(
            static fn (ConversationMessage $m): AiMessage => $m->toAiMessage(),
            $this->messages,
        );
    }

    public function id(): string
    {
        return $this->id;
    }

    public function userId(): string
    {
        return $this->userId;
    }

    public function feature(): AiFeature
    {
        return $this->feature;
    }

    public function title(): string
    {
        return $this->title;
    }

    /** @return list<ConversationMessage> */
    public function messages(): array
    {
        return $this->messages;
    }

    public function messageCount(): int
    {
        return count($this->messages);
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
