<?php

declare(strict_types=1);

namespace EruoFood\Loyalty\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use EruoFood\Loyalty\Domain\Enum\RedemptionStatus;
use EruoFood\Loyalty\Domain\Reward\Redemption;
use EruoFood\Loyalty\Domain\Reward\RedemptionRepository;
use EruoFood\Loyalty\Infrastructure\Persistence\Eloquent\Model\RedemptionModel;
use EruoFood\Shared\Domain\Paginated;
use Illuminate\Support\Str;

final class EloquentRedemptionRepository implements RedemptionRepository
{
    public function __construct(private readonly string $codePrefix)
    {
    }

    public function nextIdentity(): string
    {
        return (string) Str::orderedUuid();
    }

    public function nextCode(): string
    {
        return sprintf('%s-%s', $this->codePrefix, strtoupper(Str::random(10)));
    }

    public function findById(string $id): ?Redemption
    {
        $m = RedemptionModel::query()->find($id);

        return $m !== null ? $this->toDomain($m) : null;
    }

    public function findByCode(string $code): ?Redemption
    {
        $m = RedemptionModel::query()->where('code', $code)->first();

        return $m !== null ? $this->toDomain($m) : null;
    }

    public function forUser(string $userId, int $page, int $perPage): Paginated
    {
        $paginator = RedemptionModel::query()->where('user_id', $userId)
            ->orderByDesc('created_at')->paginate(perPage: $perPage, page: $page);

        return new Paginated(
            array_values(array_map(fn (RedemptionModel $m): Redemption => $this->toDomain($m), $paginator->items())),
            $paginator->total(),
            $page,
            $perPage,
        );
    }

    public function save(Redemption $redemption): void
    {
        $model = RedemptionModel::query()->find($redemption->id()) ?? new RedemptionModel();
        $model->id = $redemption->id();
        $model->reward_id = $redemption->rewardId();
        $model->user_id = $redemption->userId();
        $model->code = $redemption->code();
        $model->points_spent = $redemption->pointsSpent();
        $model->benefit_type = $redemption->benefitType();
        $model->benefit_value = $redemption->benefitValue();
        $model->status = $redemption->status()->value;
        $model->created_at = $redemption->createdAt();
        $model->updated_at = $redemption->updatedAt();
        $model->save();
    }

    private function toDomain(RedemptionModel $m): Redemption
    {
        return Redemption::reconstitute(
            $m->id,
            $m->reward_id,
            $m->user_id,
            $m->code,
            (int) $m->points_spent,
            $m->benefit_type,
            (int) $m->benefit_value,
            RedemptionStatus::from($m->status),
            DateTimeImmutable::createFromInterface($m->created_at),
            DateTimeImmutable::createFromInterface($m->updated_at),
        );
    }
}
