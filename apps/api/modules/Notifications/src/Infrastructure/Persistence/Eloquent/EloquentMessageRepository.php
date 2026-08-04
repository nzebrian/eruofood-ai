<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use EruoFood\Notifications\Domain\Enum\MessageType;
use EruoFood\Notifications\Domain\Messaging\Message;
use EruoFood\Notifications\Domain\Messaging\MessageRepository;
use EruoFood\Notifications\Domain\ValueObject\Attachment;
use EruoFood\Notifications\Infrastructure\Persistence\Eloquent\Model\MessageModel;
use EruoFood\Shared\Domain\Paginated;
use Illuminate\Support\Str;

final class EloquentMessageRepository implements MessageRepository
{
    public function nextIdentity(): string
    {
        return (string) Str::orderedUuid();
    }

    public function findById(string $id): ?Message
    {
        $m = MessageModel::query()->find($id);

        return $m !== null ? $this->toDomain($m) : null;
    }

    public function forConversation(string $conversationId, int $page, int $perPage): Paginated
    {
        $paginator = MessageModel::query()->where('conversation_id', $conversationId)
            ->orderByDesc('created_at')->paginate(perPage: $perPage, page: $page);

        return new Paginated(
            array_values(array_map(fn (MessageModel $m): Message => $this->toDomain($m), $paginator->items())),
            $paginator->total(),
            $page,
            $perPage,
        );
    }

    public function unreadCount(string $conversationId, string $userId): int
    {
        return (int) MessageModel::query()->where('conversation_id', $conversationId)
            ->whereJsonDoesntContain('read_by', $userId)->count();
    }

    public function save(Message $message): void
    {
        $model = MessageModel::query()->find($message->id()) ?? new MessageModel();
        $model->id = $message->id();
        $model->conversation_id = $message->conversationId();
        $model->sender_id = $message->senderId();
        $model->type = $message->type()->value;
        $model->body = $message->body();
        $model->attachments = array_map(static fn (Attachment $a): array => $a->toArray(), $message->attachments());
        $model->read_by = $message->readBy();
        $model->created_at = $message->createdAt();
        $model->save();
    }

    private function toDomain(MessageModel $m): Message
    {
        return Message::reconstitute(
            id: $m->id,
            conversationId: $m->conversation_id,
            senderId: $m->sender_id,
            type: MessageType::from($m->type),
            body: $m->body,
            attachments: array_values(array_map(static fn (array $a): Attachment => Attachment::fromArray($a), $m->attachments ?? [])),
            readBy: array_values(array_map('strval', $m->read_by ?? [])),
            createdAt: DateTimeImmutable::createFromInterface($m->created_at),
        );
    }
}
