<?php

declare(strict_types=1);

namespace EruoFood\Admin\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use EruoFood\Admin\Domain\Cms\Banner;
use EruoFood\Admin\Domain\Cms\BannerRepository;
use EruoFood\Admin\Infrastructure\Persistence\Eloquent\Model\BannerModel;
use Illuminate\Support\Str;

final class EloquentBannerRepository implements BannerRepository
{
    public function nextIdentity(): string
    {
        return (string) Str::orderedUuid();
    }

    public function findById(string $id): ?Banner
    {
        $m = BannerModel::query()->find($id);

        return $m !== null ? $this->toDomain($m) : null;
    }

    public function all(?string $placement = null): array
    {
        $builder = BannerModel::query();
        if ($placement !== null) {
            $builder->where('placement', $placement);
        }

        return array_map(
            fn (BannerModel $m): Banner => $this->toDomain($m),
            $builder->orderBy('sort_order')->get()->all(),
        );
    }

    public function save(Banner $banner): void
    {
        $model = BannerModel::query()->find($banner->id()) ?? new BannerModel();
        $model->id = $banner->id();
        $model->title = $banner->title();
        $model->image_url = $banner->imageUrl();
        $model->link_url = $banner->linkUrl();
        $model->placement = $banner->placement();
        $model->sort_order = $banner->sortOrder();
        $model->active = $banner->isActive();
        $model->starts_at = $banner->startsAt();
        $model->ends_at = $banner->endsAt();
        $model->created_at = $banner->createdAt();
        $model->save();
    }

    public function delete(string $id): void
    {
        BannerModel::query()->whereKey($id)->delete();
    }

    private function toDomain(BannerModel $m): Banner
    {
        return Banner::reconstitute(
            id: $m->id,
            title: $m->title,
            imageUrl: $m->image_url,
            linkUrl: $m->link_url,
            placement: $m->placement,
            sortOrder: (int) $m->sort_order,
            active: (bool) $m->active,
            startsAt: $m->starts_at !== null ? DateTimeImmutable::createFromInterface($m->starts_at) : null,
            endsAt: $m->ends_at !== null ? DateTimeImmutable::createFromInterface($m->ends_at) : null,
            createdAt: DateTimeImmutable::createFromInterface($m->created_at),
        );
    }
}
