<?php

declare(strict_types=1);

namespace EruoFood\Admin\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use EruoFood\Admin\Domain\Audit\AuditLogEntry;
use EruoFood\Admin\Domain\Audit\AuditLogRepository;
use EruoFood\Admin\Domain\Audit\AuditQuery;
use EruoFood\Admin\Domain\Enum\AuditCategory;
use EruoFood\Admin\Infrastructure\Persistence\Eloquent\Model\AuditLogModel;
use EruoFood\Shared\Domain\Paginated;
use Illuminate\Support\Str;

final class EloquentAuditLogRepository implements AuditLogRepository
{
    public function nextIdentity(): string
    {
        return (string) Str::orderedUuid();
    }

    public function append(AuditLogEntry $entry): void
    {
        $model = new AuditLogModel();
        $model->id = $entry->id();
        $model->actor_id = $entry->actorId();
        $model->category = $entry->category()->value;
        $model->action = $entry->action();
        $model->subject_type = $entry->subjectType();
        $model->subject_id = $entry->subjectId();
        $model->context = $entry->context();
        $model->ip = $entry->ip();
        $model->created_at = $entry->createdAt();
        $model->save();
    }

    public function query(AuditQuery $query): Paginated
    {
        $builder = AuditLogModel::query();
        if ($query->category !== null) {
            $builder->where('category', $query->category->value);
        }
        if ($query->actorId !== null) {
            $builder->where('actor_id', $query->actorId);
        }
        if ($query->subjectType !== null) {
            $builder->where('subject_type', $query->subjectType);
        }
        if ($query->subjectId !== null) {
            $builder->where('subject_id', $query->subjectId);
        }

        $paginator = $builder->orderByDesc('created_at')->paginate(perPage: $query->perPage, page: $query->page);

        return new Paginated(
            array_map(fn (AuditLogModel $m): AuditLogEntry => $this->toDomain($m), $paginator->items()),
            $paginator->total(),
            $query->page,
            $query->perPage,
        );
    }

    private function toDomain(AuditLogModel $m): AuditLogEntry
    {
        /** @var array<string, scalar|null> $context */
        $context = $m->context ?? [];

        return AuditLogEntry::reconstitute(
            id: $m->id,
            actorId: $m->actor_id,
            category: AuditCategory::from($m->category),
            action: $m->action,
            subjectType: $m->subject_type,
            subjectId: $m->subject_id,
            context: $context,
            ip: $m->ip,
            createdAt: DateTimeImmutable::createFromInterface($m->created_at),
        );
    }
}
