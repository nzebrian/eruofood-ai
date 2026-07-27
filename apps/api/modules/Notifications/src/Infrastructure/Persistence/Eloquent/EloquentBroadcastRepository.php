<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use EruoFood\Notifications\Domain\Broadcast\Broadcast;
use EruoFood\Notifications\Domain\Broadcast\BroadcastRepository;
use EruoFood\Notifications\Domain\Enum\NotificationCategory;
use EruoFood\Notifications\Infrastructure\Persistence\Eloquent\Model\BroadcastModel;
use EruoFood\Shared\Domain\Paginated;
use Illuminate\Support\Str;

final class EloquentBroadcastRepository implements BroadcastRepository
{
    public function nextIdentity(): string
    {
        return (string) Str::orderedUuid();
    }

    public function findById(string $id): ?Broadcast
    {
        $m = BroadcastModel::query()->find($id);

        return $m !== null ? $this->toDomain($m) : null;
    }

    public function all(int $page, int $perPage): Paginated
    {
        $paginator = BroadcastModel::query()->orderByDesc('created_at')->paginate(perPage: $perPage, page: $page);

        return new Paginated(
            array_map(fn (BroadcastModel $m): Broadcast => $this->toDomain($m), $paginator->items()),
            $paginator->total(),
            $page,
            $perPage,
        );
    }

    public function save(Broadcast $broadcast): void
    {
        $model = BroadcastModel::query()->find($broadcast->id()) ?? new BroadcastModel();
        $model->id = $broadcast->id();
        $model->title = $broadcast->title();
        $model->body = $broadcast->body();
        $model->category = $broadcast->category()->value;
        $model->channels = $broadcast->channels();
        $model->segment = $broadcast->segment();
        $model->scheduled_for = $broadcast->scheduledFor();
        $model->sent = $broadcast->isSent();
        $model->recipient_count = $broadcast->recipientCount();
        $model->created_at = $broadcast->createdAt();
        $model->save();
    }

    private function toDomain(BroadcastModel $m): Broadcast
    {
        return Broadcast::reconstitute(
            id: $m->id,
            title: $m->title,
            body: $m->body,
            category: NotificationCategory::from($m->category),
            channels: array_map('strval', $m->channels ?? []),
            segment: $m->segment,
            scheduledFor: $m->scheduled_for !== null ? DateTimeImmutable::createFromInterface($m->scheduled_for) : null,
            sent: $m->sent,
            recipientCount: (int) $m->recipient_count,
            createdAt: DateTimeImmutable::createFromInterface($m->created_at),
        );
    }
}
