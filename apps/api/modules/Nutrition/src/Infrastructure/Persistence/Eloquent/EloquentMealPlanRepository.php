<?php

declare(strict_types=1);

namespace EruoFood\Nutrition\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use EruoFood\Nutrition\Domain\Enum\PlanPeriod;
use EruoFood\Nutrition\Domain\Plan\MealPlan;
use EruoFood\Nutrition\Domain\Plan\MealPlanEntry;
use EruoFood\Nutrition\Domain\Plan\MealPlanRepository;
use EruoFood\Nutrition\Infrastructure\Persistence\Eloquent\Model\MealPlanModel;
use EruoFood\Shared\Domain\Paginated;
use Illuminate\Support\Str;

final class EloquentMealPlanRepository implements MealPlanRepository
{
    public function nextIdentity(): string
    {
        return (string) Str::orderedUuid();
    }

    public function findById(string $id): ?MealPlan
    {
        $model = MealPlanModel::query()->find($id);

        return $model !== null ? $this->toDomain($model) : null;
    }

    public function forUser(string $userId, int $page, int $perPage): Paginated
    {
        $paginator = MealPlanModel::query()
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->paginate(perPage: $perPage, page: $page);

        return new Paginated(
            array_values(array_map(fn (MealPlanModel $m): MealPlan => $this->toDomain($m), $paginator->items())),
            $paginator->total(),
            $page,
            $perPage,
        );
    }

    public function save(MealPlan $plan): void
    {
        $model = MealPlanModel::query()->find($plan->id()) ?? new MealPlanModel();
        $model->id = $plan->id();
        $model->user_id = $plan->userId();
        $model->title = $plan->title();
        $model->period = $plan->period()->value;
        $model->start_date = $plan->startDate();
        $model->entries = array_map(static fn (MealPlanEntry $e): array => $e->toArray(), $plan->entries());
        $model->created_at = $plan->createdAt();
        $model->save();
    }

    public function delete(string $id): void
    {
        MealPlanModel::query()->where('id', $id)->delete();
    }

    private function toDomain(MealPlanModel $m): MealPlan
    {
        $entries = array_map(
            static fn (array $e): MealPlanEntry => MealPlanEntry::fromArray($e),
            $m->entries ?? [],
        );

        return MealPlan::reconstitute(
            id: $m->id,
            userId: $m->user_id,
            title: $m->title,
            period: PlanPeriod::from($m->period),
            startDate: $m->start_date,
            entries: $entries,
            createdAt: DateTimeImmutable::createFromInterface($m->created_at),
        );
    }
}
