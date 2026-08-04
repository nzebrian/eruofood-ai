<?php

declare(strict_types=1);

namespace EruoFood\Support\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use EruoFood\Shared\Domain\Paginated;
use EruoFood\Support\Domain\Crm\CustomerProfile;
use EruoFood\Support\Domain\Crm\CustomerProfileRepository;
use EruoFood\Support\Domain\Crm\CustomerSegment;
use EruoFood\Support\Infrastructure\Persistence\Eloquent\Model\CustomerProfileModel;

final class EloquentCustomerProfileRepository implements CustomerProfileRepository
{
    public function findByUserId(string $userId): ?CustomerProfile
    {
        $m = CustomerProfileModel::query()->find($userId);

        return $m !== null ? $this->toDomain($m) : null;
    }

    public function search(?string $term, ?CustomerSegment $segment, int $page, int $perPage): Paginated
    {
        $builder = CustomerProfileModel::query();
        if ($term !== null && $term !== '') {
            $like = '%'.mb_strtolower($term).'%';
            $builder->where(function ($q) use ($like): void {
                $q->whereRaw('LOWER(display_name) LIKE ?', [$like])->orWhereRaw('LOWER(email) LIKE ?', [$like]);
            });
        }
        if ($segment !== null) {
            $builder->where('segment', $segment->value);
        }

        $paginator = $builder->orderByDesc('last_interaction_at')->paginate(perPage: $perPage, page: $page);

        return new Paginated(
            array_values(array_map(fn (CustomerProfileModel $m): CustomerProfile => $this->toDomain($m), $paginator->items())),
            $paginator->total(),
            $page,
            $perPage,
        );
    }

    public function segmentCounts(): array
    {
        /** @var array<string, int> $rows */
        $rows = CustomerProfileModel::query()->selectRaw('segment, count(*) as c')
            ->groupBy('segment')->toBase()->pluck('c', 'segment')->map(fn ($v): int => (int) $v)->all();

        return $rows;
    }

    public function save(CustomerProfile $profile): void
    {
        $model = CustomerProfileModel::query()->find($profile->userId()) ?? new CustomerProfileModel();
        $model->user_id = $profile->userId();
        $model->display_name = $profile->displayName();
        $model->email = $profile->email();
        $model->segment = $profile->segment()->value;
        $model->order_count = $profile->orderCount();
        $model->total_spent_minor = $profile->totalSpentMinor();
        $model->ticket_count = $profile->ticketCount();
        $model->tags = $profile->tags();
        $model->notes = $profile->notes();
        $model->insight = $profile->insight();
        $model->insight_generated_at = $profile->insightGeneratedAt();
        $model->last_interaction_at = $profile->lastInteractionAt();
        $model->created_at = $profile->createdAt();
        $model->updated_at = $profile->updatedAt();
        $model->save();
    }

    private function toDomain(CustomerProfileModel $m): CustomerProfile
    {
        return CustomerProfile::reconstitute(
            userId: $m->user_id,
            displayName: $m->display_name,
            email: $m->email,
            segment: CustomerSegment::from($m->segment),
            orderCount: (int) $m->order_count,
            totalSpentMinor: (int) $m->total_spent_minor,
            ticketCount: (int) $m->ticket_count,
            tags: array_values(array_map('strval', $m->tags ?? [])),
            notes: $m->notes,
            insight: $m->insight,
            insightGeneratedAt: $m->insight_generated_at !== null ? DateTimeImmutable::createFromInterface($m->insight_generated_at) : null,
            lastInteractionAt: $m->last_interaction_at !== null ? DateTimeImmutable::createFromInterface($m->last_interaction_at) : null,
            createdAt: DateTimeImmutable::createFromInterface($m->created_at),
            updatedAt: DateTimeImmutable::createFromInterface($m->updated_at),
        );
    }
}
