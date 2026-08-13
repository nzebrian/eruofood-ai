<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use EruoFood\Commerce\Domain\Enum\CouponType;
use EruoFood\Commerce\Domain\Promotion\Coupon;
use EruoFood\Commerce\Domain\Promotion\CouponRepository;
use EruoFood\Commerce\Infrastructure\Persistence\Eloquent\Model\CouponModel;
use Illuminate\Support\Str;

final class EloquentCouponRepository implements CouponRepository
{
    public function nextIdentity(): string
    {
        return (string) Str::orderedUuid();
    }

    public function findById(string $id): ?Coupon
    {
        $m = CouponModel::query()->find($id);

        return $m !== null ? $this->toDomain($m) : null;
    }

    public function findByCode(string $code): ?Coupon
    {
        $m = CouponModel::query()->where('code', strtoupper(trim($code)))->first();

        return $m !== null ? $this->toDomain($m) : null;
    }

    public function findByCodeForUpdate(string $code): ?Coupon
    {
        $m = CouponModel::query()->where('code', strtoupper(trim($code)))->lockForUpdate()->first();

        return $m !== null ? $this->toDomain($m) : null;
    }

    public function codeExists(string $code): bool
    {
        return CouponModel::query()->where('code', strtoupper(trim($code)))->exists();
    }

    public function all(): array
    {
        return array_values(array_map(
            fn (CouponModel $m): Coupon => $this->toDomain($m),
            CouponModel::query()->orderByDesc('created_at')->get()->all(),
        ));
    }

    public function save(Coupon $coupon): void
    {
        $model = CouponModel::query()->find($coupon->id()) ?? new CouponModel();
        $model->id = $coupon->id();
        $model->code = $coupon->code();
        $model->type = $coupon->type()->value;
        $model->value = $coupon->value();
        $model->min_spend_minor = $coupon->minSpendMinor();
        $model->max_redemptions = $coupon->maxRedemptions();
        $model->times_redeemed = $coupon->timesRedeemed();
        $model->expires_at = $coupon->expiresAt();
        $model->active = $coupon->isActive();
        $model->save();
    }

    private function toDomain(CouponModel $m): Coupon
    {
        return Coupon::reconstitute(
            id: $m->id,
            code: $m->code,
            type: CouponType::from($m->type),
            value: $m->value,
            minSpendMinor: $m->min_spend_minor,
            maxRedemptions: $m->max_redemptions,
            timesRedeemed: $m->times_redeemed,
            expiresAt: $m->expires_at !== null ? DateTimeImmutable::createFromInterface($m->expires_at) : null,
            active: $m->active,
        );
    }
}
