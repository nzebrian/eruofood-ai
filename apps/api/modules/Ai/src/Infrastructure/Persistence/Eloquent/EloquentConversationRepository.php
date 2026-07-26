<?php

declare(strict_types=1);

namespace EruoFood\Ai\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use EruoFood\Ai\Domain\Conversation\Conversation;
use EruoFood\Ai\Domain\Conversation\ConversationMessage;
use EruoFood\Ai\Domain\Conversation\ConversationRepository;
use EruoFood\Ai\Domain\Enum\AiFeature;
use EruoFood\Ai\Domain\Enum\MessageRole;
use EruoFood\Ai\Infrastructure\Persistence\Eloquent\Model\ConversationMessageModel;
use EruoFood\Ai\Infrastructure\Persistence\Eloquent\Model\ConversationModel;
use EruoFood\Shared\Domain\Paginated;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Eloquent-backed {@see ConversationRepository}; persists a thread and its messages. */
final class EloquentConversationRepository implements ConversationRepository
{
    public function nextIdentity(): string
    {
        return (string) Str::orderedUuid();
    }

    public function findById(string $id): ?Conversation
    {
        $model = ConversationModel::query()->with('messages')->find($id);

        return $model !== null ? $this->toDomain($model) : null;
    }

    public function forUser(string $userId, int $page, int $perPage): Paginated
    {
        $paginator = ConversationModel::query()
            ->with('messages')
            ->where('user_id', $userId)
            ->orderByDesc('updated_at')
            ->paginate(perPage: $perPage, page: $page);

        return new Paginated(
            array_map(fn (ConversationModel $m): Conversation => $this->toDomain($m), $paginator->items()),
            $paginator->total(),
            $page,
            $perPage,
        );
    }

    public function save(Conversation $conversation): void
    {
        DB::transaction(function () use ($conversation): void {
            $model = ConversationModel::query()->find($conversation->id()) ?? new ConversationModel();
            $model->id = $conversation->id();
            $model->user_id = $conversation->userId();
            $model->feature = $conversation->feature()->value;
            $model->title = $conversation->title();
            $model->created_at = $conversation->createdAt();
            $model->updated_at = $conversation->updatedAt();
            $model->save();

            // Rewrite the message list; threads are small and append-only.
            ConversationMessageModel::query()->where('conversation_id', $conversation->id())->delete();

            $position = 0;
            foreach ($conversation->messages() as $message) {
                $row = new ConversationMessageModel();
                $row->id = (string) Str::orderedUuid();
                $row->conversation_id = $conversation->id();
                $row->position = $position++;
                $row->role = $message->role->value;
                $row->content = $message->content;
                $row->created_at = $message->createdAt;
                $row->save();
            }
        });
    }

    public function delete(string $id): void
    {
        DB::transaction(function () use ($id): void {
            ConversationMessageModel::query()->where('conversation_id', $id)->delete();
            ConversationModel::query()->where('id', $id)->delete();
        });
    }

    private function toDomain(ConversationModel $m): Conversation
    {
        /** @var list<ConversationMessage> $messages */
        $messages = $m->messages
            ->map(static fn (ConversationMessageModel $row): ConversationMessage => new ConversationMessage(
                MessageRole::from($row->role),
                $row->content,
                DateTimeImmutable::createFromInterface($row->created_at),
            ))
            ->all();

        return Conversation::reconstitute(
            id: $m->id,
            userId: $m->user_id,
            feature: AiFeature::from($m->feature),
            title: $m->title,
            messages: $messages,
            createdAt: DateTimeImmutable::createFromInterface($m->created_at),
            updatedAt: DateTimeImmutable::createFromInterface($m->updated_at),
        );
    }
}
