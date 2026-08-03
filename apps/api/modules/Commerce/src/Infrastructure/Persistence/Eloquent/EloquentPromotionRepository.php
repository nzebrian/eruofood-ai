<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use EruoFood\Commerce\Domain\Enum\PromotionType;
use EruoFood\Commerce\Domain\Promotion\Promotion;
use EruoFood\Commerce\Domain\Promotion\PromotionRepository;
use EruoFood\Commerce\Infrastructure\Persistence\Eloquent\Model\PromotionModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

final class EloquentPromotionRepository implements PromotionRepository
{
    public function nextIdentity(): string
    {
        return (string) Str::orderedUuid();
    }

    public function findById(string $id): ?Promotion
    {
        $m = PromotionModel::query()->find($id);

        return $m !== null ? $this->toDomain($m) : null;
    }

    public function activeAt(DateTimeImmutable $now): array
    {
        return array_values(array_map(
            fn (PromotionModel $m): Promotion => $this->toDomain($m),
            $this->activeQuery($now)->orderByDesc('flash_sale')->get()->all(),
        ));
    }

    public function activeFlashSales(DateTimeImmutable $now): array
    {
        return array_values(array_map(
            fn (PromotionModel $m): Promotion => $this->toDomain($m),
            $this->activeQuery($now)->where('flash_sale', true)->orderBy('ends_at')->get()->all(),
        ));
    }

    public function forStore(string $storeId): array
    {
        return array_values(array_map(
            fn (PromotionModel $m): Promotion => $this->toDomain($m),
            PromotionModel::query()->where('store_id', $storeId)->orderByDesc('created_at')->get()->all(),
        ));
    }

    public function save(Promotion $promotion): void
    {
        $model = PromotionModel::query()->find($promotion->id()) ?? new PromotionModel();
        $model->id = $promotion->id();
        $model->store_id = $promotion->storeId();
        $model->name = $promotion->name();
        $model->type = $promotion->type()->value;
        $model->value = $promotion->value();
        $model->product_ids = $promotion->productIds();
        $model->starts_at = $promotion->startsAt();
        $model->ends_at = $promotion->endsAt();
        $model->flash_sale = $promotion->isFlashSale();
        $model->save();
    }

    public function delete(string $id): void
    {
        PromotionModel::query()->where('id', $id)->delete();
    }

    /** @return Builder<PromotionModel> */
    private function activeQuery(DateTimeImmutable $now): Builder
    {
        $ts = $now->format('Y-m-d H:i:s');

        return PromotionModel::query()
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $ts))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $ts));
    }

    private function toDomain(PromotionModel $m): Promotion
    {
        return Promotion::reconstitute(
            id: $m->id,
            storeId: $m->store_id,
            name: $m->name,
            type: PromotionType::from($m->type),
            value: $m->value,
            productIds: array_map('strval', $m->product_ids ?? []),
            startsAt: $m->starts_at !== null ? DateTimeImmutable::createFromInterface($m->starts_at) : null,
            endsAt: $m->ends_at !== null ? DateTimeImmutable::createFromInterface($m->ends_at) : null,
            flashSale: $m->flash_sale,
        );
    }
}
