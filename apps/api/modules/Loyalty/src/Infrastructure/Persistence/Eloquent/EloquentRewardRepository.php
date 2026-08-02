<?php

declare(strict_types=1);

namespace EruoFood\Loyalty\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use EruoFood\Loyalty\Domain\Reward\Reward;
use EruoFood\Loyalty\Domain\Reward\RewardRepository;
use EruoFood\Loyalty\Infrastructure\Persistence\Eloquent\Model\RewardModel;
use EruoFood\Shared\Domain\Paginated;
use Illuminate\Support\Str;

final class EloquentRewardRepository implements RewardRepository
{
    public function nextIdentity(): string
    {
        return (string) Str::orderedUuid();
    }

    public function findById(string $id): ?Reward
    {
        $m = RewardModel::query()->find($id);

        return $m !== null ? $this->toDomain($m) : null;
    }

    public function catalogue(bool $activeOnly, int $page, int $perPage): Paginated
    {
        $builder = RewardModel::query();
        if ($activeOnly) {
            $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
            $builder->where('active', true)
                ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
                ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now))
                ->where(fn ($q) => $q->whereNull('stock')->orWhere('stock', '>', 0));
        }
        $paginator = $builder->orderBy('points_cost')->paginate(perPage: $perPage, page: $page);

        return new Paginated(
            array_map(fn (RewardModel $m): Reward => $this->toDomain($m), $paginator->items()),
            $paginator->total(),
            $page,
            $perPage,
        );
    }

    public function save(Reward $reward): void
    {
        $model = RewardModel::query()->find($reward->id()) ?? new RewardModel();
        $model->id = $reward->id();
        $model->name = $reward->name();
        $model->description = $reward->description();
        $model->benefit_type = $reward->benefitType();
        $model->benefit_value = $reward->benefitValue();
        $model->points_cost = $reward->pointsCost();
        $model->stock = $reward->stock();
        $model->active = $reward->isActive();
        $model->starts_at = $reward->startsAt();
        $model->ends_at = $reward->endsAt();
        $model->created_at = $reward->createdAt();
        $model->save();
    }

    private function toDomain(RewardModel $m): Reward
    {
        return Reward::reconstitute(
            $m->id,
            $m->name,
            (string) ($m->description ?? ''),
            $m->benefit_type,
            (int) $m->benefit_value,
            (int) $m->points_cost,
            $m->stock !== null ? (int) $m->stock : null,
            (bool) $m->active,
            DateTimeImmutable::createFromInterface($m->created_at),
            $m->starts_at !== null ? DateTimeImmutable::createFromInterface($m->starts_at) : null,
            $m->ends_at !== null ? DateTimeImmutable::createFromInterface($m->ends_at) : null,
        );
    }
}
