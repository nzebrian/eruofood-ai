<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use EruoFood\Marketplace\Domain\Enum\RiderStatus;
use EruoFood\Marketplace\Domain\Rider\Rider;
use EruoFood\Marketplace\Domain\Rider\RiderRepository;
use EruoFood\Marketplace\Domain\ValueObject\GeoLocation;
use EruoFood\Marketplace\Infrastructure\Persistence\Eloquent\Model\RiderModel;
use Illuminate\Support\Str;

final class EloquentRiderRepository implements RiderRepository
{
    public function nextIdentity(): string
    {
        return (string) Str::orderedUuid();
    }

    public function findById(string $id): ?Rider
    {
        $m = RiderModel::query()->find($id);

        return $m !== null ? $this->toDomain($m) : null;
    }

    public function findByUser(string $userId): ?Rider
    {
        $m = RiderModel::query()->where('user_id', $userId)->first();

        return $m !== null ? $this->toDomain($m) : null;
    }

    public function available(int $limit = 20): array
    {
        return array_values(array_map(
            fn (RiderModel $m): Rider => $this->toDomain($m),
            RiderModel::query()->where('status', RiderStatus::Available->value)->limit($limit)->get()->all(),
        ));
    }

    public function save(Rider $rider): void
    {
        $model = RiderModel::query()->find($rider->id()) ?? new RiderModel();
        $model->id = $rider->id();
        $model->user_id = $rider->userId();
        $model->name = $rider->name();
        $model->phone = $rider->phone();
        $model->vehicle_type = $rider->vehicleType();
        $model->status = $rider->status()->value;
        $model->latitude = $rider->location()?->latitude;
        $model->longitude = $rider->location()?->longitude;
        $model->created_at = $rider->createdAt();
        $model->save();
    }

    private function toDomain(RiderModel $m): Rider
    {
        return Rider::reconstitute(
            id: $m->id,
            userId: $m->user_id,
            name: $m->name,
            phone: $m->phone,
            vehicleType: $m->vehicle_type,
            status: RiderStatus::from($m->status),
            location: $m->latitude !== null && $m->longitude !== null ? new GeoLocation($m->latitude, $m->longitude) : null,
            createdAt: DateTimeImmutable::createFromInterface($m->created_at),
        );
    }
}
