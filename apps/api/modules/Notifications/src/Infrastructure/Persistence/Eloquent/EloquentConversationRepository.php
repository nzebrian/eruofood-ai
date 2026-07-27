<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use EruoFood\Notifications\Domain\Enum\ConversationType;
use EruoFood\Notifications\Domain\Messaging\Conversation;
use EruoFood\Notifications\Domain\Messaging\ConversationRepository;
use EruoFood\Notifications\Infrastructure\Persistence\Eloquent\Model\ConversationModel;
use Illuminate\Support\Str;

final class EloquentConversationRepository implements ConversationRepository
{
    public function nextIdentity(): string
    {
        return (string) Str::orderedUuid();
    }

    public function findById(string $id): ?Conversation
    {
        $m = ConversationModel::query()->find($id);

        return $m !== null ? $this->toDomain($m) : null;
    }

    public function forUser(string $userId): array
    {
        return array_map(
            fn (ConversationModel $m): Conversation => $this->toDomain($m),
            ConversationModel::query()->whereJsonContains('participant_ids', $userId)
                ->orderByDesc('last_message_at')->get()->all(),
        );
    }

    public function save(Conversation $conversation): void
    {
        $model = ConversationModel::query()->find($conversation->id()) ?? new ConversationModel();
        $model->id = $conversation->id();
        $model->type = $conversation->type()->value;
        $model->participant_ids = $conversation->participantIds();
        $model->subject = $conversation->subject();
        $model->context_ref = $conversation->contextRef();
        $model->last_message_at = $conversation->lastMessageAt();
        $model->created_at = $conversation->createdAt();
        $model->save();
    }

    private function toDomain(ConversationModel $m): Conversation
    {
        return Conversation::reconstitute(
            id: $m->id,
            type: ConversationType::from($m->type),
            participantIds: array_map('strval', $m->participant_ids ?? []),
            subject: $m->subject,
            contextRef: $m->context_ref,
            lastMessageAt: DateTimeImmutable::createFromInterface($m->last_message_at),
            createdAt: DateTimeImmutable::createFromInterface($m->created_at),
        );
    }
}
