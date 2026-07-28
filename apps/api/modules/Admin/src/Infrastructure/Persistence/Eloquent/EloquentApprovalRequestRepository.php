<?php

declare(strict_types=1);

namespace EruoFood\Admin\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use EruoFood\Admin\Domain\Operations\ApprovalKind;
use EruoFood\Admin\Domain\Operations\ApprovalRequest;
use EruoFood\Admin\Domain\Operations\ApprovalRequestRepository;
use EruoFood\Admin\Domain\Operations\ApprovalStatus;
use EruoFood\Admin\Infrastructure\Persistence\Eloquent\Model\ApprovalRequestModel;
use EruoFood\Shared\Domain\Paginated;
use Illuminate\Support\Str;

final class EloquentApprovalRequestRepository implements ApprovalRequestRepository
{
    public function nextIdentity(): string
    {
        return (string) Str::orderedUuid();
    }

    public function findById(string $id): ?ApprovalRequest
    {
        $m = ApprovalRequestModel::query()->find($id);

        return $m !== null ? $this->toDomain($m) : null;
    }

    public function search(?ApprovalStatus $status, ?string $subjectType, int $page, int $perPage): Paginated
    {
        $builder = ApprovalRequestModel::query();
        if ($status !== null) {
            $builder->where('status', $status->value);
        }
        if ($subjectType !== null) {
            $builder->where('subject_type', $subjectType);
        }
        $paginator = $builder->orderByDesc('submitted_at')->paginate(perPage: $perPage, page: $page);

        return new Paginated(
            array_map(fn (ApprovalRequestModel $m): ApprovalRequest => $this->toDomain($m), $paginator->items()),
            $paginator->total(),
            $page,
            $perPage,
        );
    }

    public function save(ApprovalRequest $request): void
    {
        $model = ApprovalRequestModel::query()->find($request->id()) ?? new ApprovalRequestModel();
        $model->id = $request->id();
        $model->subject_type = $request->subjectType();
        $model->subject_id = $request->subjectId();
        $model->kind = $request->kind()->value;
        $model->details = $request->details();
        $model->status = $request->status()->value;
        $model->decided_by = $request->decidedBy();
        $model->note = $request->note();
        $model->submitted_at = $request->submittedAt();
        $model->decided_at = $request->decidedAt();
        $model->save();
    }

    private function toDomain(ApprovalRequestModel $m): ApprovalRequest
    {
        /** @var array<string, scalar|null> $details */
        $details = $m->details ?? [];

        return ApprovalRequest::reconstitute(
            id: $m->id,
            subjectType: $m->subject_type,
            subjectId: $m->subject_id,
            kind: ApprovalKind::from($m->kind),
            details: $details,
            status: ApprovalStatus::from($m->status),
            decidedBy: $m->decided_by,
            note: $m->note,
            submittedAt: DateTimeImmutable::createFromInterface($m->submitted_at),
            decidedAt: $m->decided_at !== null ? DateTimeImmutable::createFromInterface($m->decided_at) : null,
        );
    }
}
