<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use EruoFood\Notifications\Domain\Enum\NotificationCategory;
use EruoFood\Notifications\Domain\Enum\NotificationChannel;
use EruoFood\Notifications\Domain\Enum\NotificationStatus;
use EruoFood\Notifications\Domain\Enum\Priority;
use EruoFood\Notifications\Domain\Notification\DeliveryStatsRepository;
use EruoFood\Notifications\Domain\Notification\Notification;
use EruoFood\Notifications\Domain\Notification\NotificationRepository;
use EruoFood\Notifications\Domain\ValueObject\RenderedContent;
use EruoFood\Notifications\Infrastructure\Persistence\Eloquent\Model\NotificationModel;
use EruoFood\Shared\Domain\Paginated;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EloquentNotificationRepository implements NotificationRepository, DeliveryStatsRepository
{
    public function nextIdentity(): string
    {
        return (string) Str::orderedUuid();
    }

    public function findById(string $id): ?Notification
    {
        $m = NotificationModel::query()->find($id);

        return $m !== null ? $this->toDomain($m) : null;
    }

    public function forUser(string $userId, bool $unreadOnly, int $page, int $perPage): Paginated
    {
        $query = NotificationModel::query()->where('user_id', $userId)->where('channel', NotificationChannel::InApp->value);
        if ($unreadOnly) {
            $query->whereNull('read_at');
        }
        $paginator = $query->orderByDesc('created_at')->paginate(perPage: $perPage, page: $page);

        return new Paginated(
            array_values(array_map(fn (NotificationModel $m): Notification => $this->toDomain($m), $paginator->items())),
            $paginator->total(),
            $page,
            $perPage,
        );
    }

    public function unreadCount(string $userId): int
    {
        return (int) NotificationModel::query()->where('user_id', $userId)
            ->where('channel', NotificationChannel::InApp->value)->whereNull('read_at')->count();
    }

    public function dueForDispatch(NotificationChannel $channel, int $limit): array
    {
        return array_values(array_map(
            fn (NotificationModel $m): Notification => $this->toDomain($m),
            NotificationModel::query()
                ->where('channel', $channel->value)
                ->whereIn('status', [NotificationStatus::Pending->value, NotificationStatus::Queued->value])
                ->where(fn ($q) => $q->whereNull('scheduled_for')->orWhere('scheduled_for', '<=', now()))
                ->orderBy('created_at')->limit($limit)->get()->all(),
        ));
    }

    public function retryable(int $maxAttempts, int $limit): array
    {
        return array_values(array_map(
            fn (NotificationModel $m): Notification => $this->toDomain($m),
            NotificationModel::query()->where('status', NotificationStatus::Failed->value)
                ->where('attempts', '<', $maxAttempts)->orderBy('created_at')->limit($limit)->get()->all(),
        ));
    }

    public function markAllRead(string $userId): void
    {
        NotificationModel::query()->where('user_id', $userId)->whereNull('read_at')->update(['read_at' => now()]);
    }

    public function save(Notification $notification): void
    {
        $model = NotificationModel::query()->find($notification->id()) ?? new NotificationModel();
        $model->id = $notification->id();
        $model->user_id = $notification->userId();
        $model->category = $notification->category()->value;
        $model->channel = $notification->channel()->value;
        $model->template_key = $notification->templateKey();
        $model->data = $notification->data();
        $model->subject = $notification->content()->subject;
        $model->body = $notification->content()->body;
        $model->priority = $notification->priority()->value;
        $model->status = $notification->status()->value;
        $model->attempts = $notification->attempts();
        $model->scheduled_for = $notification->scheduledFor();
        $model->read_at = $notification->readAt();
        $model->timeline = $notification->timeline();
        $model->created_at = $notification->createdAt();
        $model->save();
    }

    public function countByStatus(): array
    {
        /** @var array<string, int> $rows */
        $rows = NotificationModel::query()->select('status', DB::raw('count(*) as c'))
            ->groupBy('status')->pluck('c', 'status')->map(fn ($v): int => (int) $v)->all();

        return $rows;
    }

    public function countByChannel(): array
    {
        /** @var array<string, int> $rows */
        $rows = NotificationModel::query()->select('channel', DB::raw('count(*) as c'))
            ->groupBy('channel')->pluck('c', 'channel')->map(fn ($v): int => (int) $v)->all();

        return $rows;
    }

    private function toDomain(NotificationModel $m): Notification
    {
        return Notification::reconstitute(
            id: $m->id,
            userId: $m->user_id,
            category: NotificationCategory::from($m->category),
            channel: NotificationChannel::from($m->channel),
            templateKey: $m->template_key,
            data: $m->data ?? [],
            content: new RenderedContent($m->subject, $m->body),
            priority: Priority::from($m->priority),
            status: NotificationStatus::from($m->status),
            attempts: (int) $m->attempts,
            scheduledFor: $m->scheduled_for !== null ? DateTimeImmutable::createFromInterface($m->scheduled_for) : null,
            readAt: $m->read_at !== null ? DateTimeImmutable::createFromInterface($m->read_at) : null,
            timeline: $m->timeline ?? [],
            createdAt: DateTimeImmutable::createFromInterface($m->created_at),
        );
    }
}
