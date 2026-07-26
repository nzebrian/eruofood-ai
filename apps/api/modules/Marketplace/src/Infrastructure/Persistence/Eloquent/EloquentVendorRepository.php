<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use EruoFood\Marketplace\Domain\Enum\VendorStatus;
use EruoFood\Marketplace\Domain\Enum\VendorType;
use EruoFood\Marketplace\Domain\ValueObject\Address;
use EruoFood\Marketplace\Domain\ValueObject\Branch;
use EruoFood\Marketplace\Domain\ValueObject\BusinessHours;
use EruoFood\Marketplace\Domain\ValueObject\ContactInfo;
use EruoFood\Marketplace\Domain\ValueObject\DeliveryZone;
use EruoFood\Marketplace\Domain\Vendor\Vendor;
use EruoFood\Marketplace\Domain\Vendor\VendorRepository;
use EruoFood\Marketplace\Domain\Vendor\VendorSearchCriteria;
use EruoFood\Marketplace\Infrastructure\Persistence\Eloquent\Model\VendorModel;
use EruoFood\Shared\Domain\Paginated;
use EruoFood\Shared\Domain\ValueObject\Slug;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

final class EloquentVendorRepository implements VendorRepository
{
    public function __construct(private readonly string $currency)
    {
    }

    public function nextIdentity(): string
    {
        return (string) Str::orderedUuid();
    }

    public function findById(string $id): ?Vendor
    {
        $m = VendorModel::query()->find($id);

        return $m !== null ? $this->toDomain($m) : null;
    }

    public function findBySlug(string $slug): ?Vendor
    {
        $m = VendorModel::query()->where('slug', $slug)->first();

        return $m !== null ? $this->toDomain($m) : null;
    }

    public function slugExists(string $slug): bool
    {
        return VendorModel::query()->where('slug', $slug)->exists();
    }

    public function search(VendorSearchCriteria $criteria, int $page, int $perPage): Paginated
    {
        $query = VendorModel::query()->where('status', VendorStatus::Verified->value);

        if ($criteria->term !== null && $criteria->term !== '') {
            $query->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($criteria->term).'%']);
        }
        if ($criteria->type !== null) {
            $query->where('type', $criteria->type->value);
        }
        if ($criteria->category !== null && $criteria->category !== '') {
            $query->where('category', $criteria->category);
        }
        if ($criteria->featuredOnly) {
            $query->where('featured', true);
        }

        $near = $criteria->near;
        if ($near !== null && $criteria->radiusKm !== null) {
            // Bounding box pre-filter (approx 111 km per degree).
            $dLat = $criteria->radiusKm / 111.0;
            $dLng = $criteria->radiusKm / (111.0 * max(0.01, cos(deg2rad($near->latitude))));
            $query->whereBetween('latitude', [$near->latitude - $dLat, $near->latitude + $dLat])
                ->whereBetween('longitude', [$near->longitude - $dLng, $near->longitude + $dLng]);
        }

        $this->applySort($query, $criteria);

        $paginator = $query->paginate(perPage: $perPage, page: $page);

        return new Paginated(
            array_map(fn (VendorModel $m): Vendor => $this->toDomain($m), $paginator->items()),
            $paginator->total(),
            $page,
            $perPage,
        );
    }

    public function forOwner(string $ownerUserId): array
    {
        return array_map(
            fn (VendorModel $m): Vendor => $this->toDomain($m),
            VendorModel::query()->where('owner_user_id', $ownerUserId)->orderByDesc('created_at')->get()->all(),
        );
    }

    public function save(Vendor $vendor): void
    {
        $model = VendorModel::query()->find($vendor->id()) ?? new VendorModel();
        $location = $vendor->location();
        $model->id = $vendor->id();
        $model->owner_user_id = $vendor->ownerUserId();
        $model->name = $vendor->name();
        $model->slug = (string) $vendor->slug();
        $model->type = $vendor->type()->value;
        $model->status = $vendor->status()->value;
        $model->category = $vendor->category();
        $model->description = $vendor->description();
        $model->contact = $vendor->contact()->toArray();
        $model->address = $vendor->address()->toArray();
        $model->branches = array_map(static fn (Branch $b): array => $b->toArray(), $vendor->branches());
        $model->business_hours = $vendor->businessHours()->toArray();
        $model->delivery_zones = array_map(static fn (DeliveryZone $z): array => $z->toArray(), $vendor->deliveryZones());
        $model->images = $vendor->images();
        $model->featured = $vendor->isFeatured();
        $model->rating_average = $vendor->ratingAverage();
        $model->rating_count = $vendor->ratingCount();
        $model->latitude = $location?->latitude;
        $model->longitude = $location?->longitude;
        $model->created_at = $vendor->createdAt();
        $model->save();
    }

    /** @param Builder<VendorModel> $query */
    private function applySort(Builder $query, VendorSearchCriteria $criteria): void
    {
        $near = $criteria->near;
        match ($criteria->sort) {
            'name' => $query->orderBy('name'),
            'recent' => $query->orderByDesc('created_at'),
            'nearest' => $near !== null
                ? $query->orderByRaw(
                    '(latitude - ?) * (latitude - ?) + (longitude - ?) * (longitude - ?)',
                    [$near->latitude, $near->latitude, $near->longitude, $near->longitude],
                )
                : $query->orderByDesc('rating_average'),
            default => $query->orderByDesc('rating_average'),
        };
    }

    private function toDomain(VendorModel $m): Vendor
    {
        return Vendor::reconstitute(
            id: $m->id,
            ownerUserId: $m->owner_user_id,
            name: $m->name,
            slug: new Slug($m->slug),
            type: VendorType::from($m->type),
            status: VendorStatus::from($m->status),
            category: $m->category,
            description: $m->description,
            contact: ContactInfo::fromArray($m->contact ?? []),
            address: Address::fromArray($m->address ?? []),
            branches: array_map(static fn (array $b): Branch => Branch::fromArray($b), $m->branches ?? []),
            businessHours: BusinessHours::fromArray($m->business_hours ?? []),
            deliveryZones: array_map(fn (array $z): DeliveryZone => DeliveryZone::fromArray($z, $this->currency), $m->delivery_zones ?? []),
            images: $m->images ?? [],
            featured: $m->featured,
            ratingAverage: $m->rating_average,
            ratingCount: $m->rating_count,
            createdAt: DateTimeImmutable::createFromInterface($m->created_at),
        );
    }
}
