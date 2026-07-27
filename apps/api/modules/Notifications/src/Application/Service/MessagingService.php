<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Application\Service;

use DateTimeImmutable;
use EruoFood\Notifications\Application\Port\RealtimeBroadcaster;
use EruoFood\Notifications\Domain\Enum\ConversationType;
use EruoFood\Notifications\Domain\Enum\MessageType;
use EruoFood\Notifications\Domain\Enum\NotificationCategory;
use EruoFood\Notifications\Domain\Enum\NotificationChannel;
use EruoFood\Notifications\Domain\Event\MessageSent;
use EruoFood\Notifications\Domain\Exception\NotificationsNotFound;
use EruoFood\Notifications\Domain\Messaging\Conversation;
use EruoFood\Notifications\Domain\Messaging\ConversationRepository;
use EruoFood\Notifications\Domain\Messaging\Message;
use EruoFood\Notifications\Domain\Messaging\MessageRepository;
use EruoFood\Notifications\Domain\ValueObject\Attachment;
use EruoFood\Shared\Domain\EventBus;
use EruoFood\Shared\Domain\Paginated;

/**
 * Real-time chat: conversations (customer ↔ restaurant/vendor/rider, admin ↔
 * user, group announcements), messages with attachments, read receipts and
 * typing indicators. Sending a message pushes a real-time event to the other
 * participants and raises an in-app notification for them (through the engine),
 * so nothing here talks to a channel directly.
 */
final readonly class MessagingService
{
    public function __construct(
        private ConversationRepository $conversations,
        private MessageRepository $messages,
        private RealtimeBroadcaster $realtime,
        private NotificationService $notifications,
        private EventBus $events,
    ) {
    }

    /**
     * @param list<string> $participantIds
     */
    public function startConversation(
        string $creatorId,
        ConversationType $type,
        array $participantIds,
        ?string $subject,
        ?string $contextRef,
    ): Conversation {
        $participants = array_values(array_unique([$creatorId, ...$participantIds]));
        $conversation = Conversation::open(
            $this->conversations->nextIdentity(),
            $type,
            $participants,
            $subject,
            $contextRef,
            new DateTimeImmutable(),
        );
        $this->conversations->save($conversation);

        return $conversation;
    }

    /** @return list<Conversation> */
    public function inbox(string $userId): array
    {
        return $this->conversations->forUser($userId);
    }

    public function getConversation(string $conversationId, string $userId): Conversation
    {
        $conversation = $this->conversations->findById($conversationId) ?? throw NotificationsNotFound::of('conversation', $conversationId);
        $conversation->assertParticipant($userId);

        return $conversation;
    }

    /**
     * @param list<Attachment> $attachments
     */
    public function send(string $conversationId, string $senderId, MessageType $type, string $body, array $attachments): Message
    {
        $conversation = $this->getConversation($conversationId, $senderId);
        $now = new DateTimeImmutable();

        $message = Message::create(
            $this->messages->nextIdentity(),
            $conversationId,
            $senderId,
            $type,
            $body,
            $attachments,
            $now,
        );
        $this->messages->save($message);

        $conversation->touch($now);
        $this->conversations->save($conversation);

        // Real-time push + in-app notification to the other participants.
        $this->realtime->broadcast('conversation.'.$conversationId, 'message.created', [
            'id' => $message->id(),
            'sender_id' => $senderId,
            'type' => $type->value,
            'body' => $body,
        ]);
        foreach ($conversation->participantIds() as $participant) {
            if ($participant === $senderId) {
                continue;
            }
            $this->notifications->notify(
                $participant,
                NotificationCategory::Admin,
                'new_message',
                ['conversation_id' => $conversationId, 'preview' => mb_substr($body, 0, 80)],
                [NotificationChannel::InApp, NotificationChannel::Push],
            );
        }

        $this->events->publish(new MessageSent($message->id(), $conversationId, $senderId));

        return $message;
    }

    /** @return Paginated<Message> */
    public function messages(string $conversationId, string $userId, int $page, int $perPage): Paginated
    {
        $this->getConversation($conversationId, $userId);

        return $this->messages->forConversation($conversationId, $page, $perPage);
    }

    public function markRead(string $messageId, string $userId): Message
    {
        $message = $this->messages->findById($messageId) ?? throw NotificationsNotFound::of('message', $messageId);
        $this->getConversation($message->conversationId(), $userId);
        $message->markReadBy($userId);
        $this->messages->save($message);

        $this->realtime->broadcast('conversation.'.$message->conversationId(), 'message.read', [
            'message_id' => $messageId,
            'user_id' => $userId,
        ]);

        return $message;
    }

    /** Broadcast a transient typing indicator (not persisted). */
    public function typing(string $conversationId, string $userId, bool $isTyping): void
    {
        $this->getConversation($conversationId, $userId);
        $this->realtime->broadcast('conversation.'.$conversationId, 'typing', [
            'user_id' => $userId,
            'typing' => $isTyping,
        ]);
    }
}
