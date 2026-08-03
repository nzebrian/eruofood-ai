<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use EruoFood\Commerce\Domain\Store\Store;
use EruoFood\Commerce\Domain\Store\StoreRepository;
use EruoFood\Commerce\Domain\ValueObject\Address;
use EruoFood\Commerce\Infrastructure\Persistence\Eloquent\Model\StoreModel;
use EruoFood\Shared\Domain\Paginated;
use EruoFood\Shared\Domain\ValueObject\Slug;
use Illuminate\Support\Str;

final class EloquentStoreRepository implements StoreRepository
{
    public function nextIdentity(): string
    {
        return (string) Str::orderedUuid();
    }

    public function findById(string $id): ?Store
    {
        $m = StoreModel::query()->find($id);

        return $m !== null ? $this->toDomain($m) : null;
    }

    public function findBySlug(string $slug): ?Store
    {
        $m = StoreModel::query()->where('slug', $slug)->first();

        return $m !== null ? $this->toDomain($m) : null;
    }

    public function slugExists(string $slug): bool
    {
        return StoreModel::query()->where('slug', $slug)->exists();
    }

    public function forOwner(string $ownerUserId): array
    {
        return array_values(array_map(
            fn (StoreModel $m): Store => $this->toDomain($m),
            StoreModel::query()->where('owner_user_id', $ownerUserId)->orderByDesc('created_at')->get()->all(),
        ));
    }

    public function listVerified(?string $term, int $page, int $perPage): Paginated
    {
        $query = StoreModel::query()->where('verified', true);
        if ($term !== null && $term !== '') {
            $query->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($term).'%']);
        }
        $query->orderByDesc('rating_average');

        $paginator = $query->paginate(perPage: $perPage, page: $page);

        return new Paginated(
            array_values(array_map(fn (StoreModel $m): Store => $this->toDomain($m), $paginator->items())),
            $paginator->total(),
            $page,
            $perPage,
        );
    }

    public function save(Store $store): void
    {
        $model = StoreModel::query()->find($store->id()) ?? new StoreModel();
        $model->id = $store->id();
        $model->owner_user_id = $store->ownerUserId();
        $model->name = $store->name();
        $model->slug = (string) $store->slug();
        $model->verified = $store->isVerified();
        $model->description = $store->description();
        $model->logo = $store->logo();
        $model->address = $store->address()?->toArray();
        $model->support_email = $store->supportEmail();
        $model->support_phone = $store->supportPhone();
        $model->rating_average = $store->ratingAverage();
        $model->rating_count = $store->ratingCount();
        $model->created_at = $store->createdAt();
        $model->save();
    }

    private function toDomain(StoreModel $m): Store
    {
        return Store::reconstitute(
            id: $m->id,
            ownerUserId: $m->owner_user_id,
            name: $m->name,
            slug: new Slug($m->slug),
            verified: $m->verified,
            description: $m->description,
            logo: $m->logo,
            address: $m->address !== null ? Address::fromArray($m->address) : null,
            supportEmail: $m->support_email,
            supportPhone: $m->support_phone,
            ratingAverage: $m->rating_average,
            ratingCount: $m->rating_count,
            createdAt: DateTimeImmutable::createFromInterface($m->created_at),
        );
    }
}
