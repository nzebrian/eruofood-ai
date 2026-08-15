<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use DateTimeInterface;
use EruoFood\Dispatch\Domain\Enum\VehicleStatus;
use EruoFood\Dispatch\Domain\Enum\VehicleType;
use EruoFood\Dispatch\Domain\Enum\VehicleVerificationState;
use EruoFood\Dispatch\Domain\Vehicle\Vehicle;
use EruoFood\Dispatch\Domain\Vehicle\VehicleRepository;
use EruoFood\Dispatch\Infrastructure\Persistence\Eloquent\Model\VehicleModel;
use EruoFood\Shared\Domain\Exception\ConcurrencyConflict;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Eloquent persistence for {@see Vehicle}.
 *
 * The dispatchability predicate is expressed in SQL rather than by loading
 * every vehicle and asking the aggregate. That is a duplication of
 * {@see Vehicle::isDispatchable()} and it is the deliberate kind: candidate
 * discovery filters a pool of riders on every dispatch, and doing that in PHP
 * would mean hydrating the whole fleet to discard most of it.
 *
 * `VehicleDispatchabilityParityTest` runs both paths over the same fixtures and
 * asserts they agree, so the copy cannot drift into disagreeing with the domain
 * — which would show up as riders mysteriously never being offered work.
 */
final class EloquentVehicleRepository implements VehicleRepository
{
    public function nextIdentity(): string
    {
        return (string) Str::uuid();
    }

    public function find(string $id): ?Vehicle
    {
        $model = VehicleModel::query()->find($id);

        return $model !== null ? $this->toDomain($model) : null;
    }

    public function forRider(string $riderId): array
    {
        return $this->hydrate(
            VehicleModel::query()
                ->where('rider_id', $riderId)
                ->orderByDesc('is_primary')
                ->orderBy('created_at')
                ->get()
                ->all(),
        );
    }

    public function dispatchableFor(string $riderId, DateTimeImmutable $now): array
    {
        return $this->hydrate(
            $this->dispatchable(VehicleModel::query()->where('rider_id', $riderId), $now)
                ->orderByDesc('is_primary')
                ->get()
                ->all(),
        );
    }

    public function dispatchableForRiders(array $riderIds, DateTimeImmutable $now): array
    {
        if ($riderIds === []) {
            return [];
        }

        $models = $this->dispatchable(
            VehicleModel::query()->whereIn('rider_id', array_values(array_unique($riderIds))),
            $now,
        )->orderByDesc('is_primary')->get();

        /** @var array<string, list<Vehicle>> $byRider */
        $byRider = [];

        foreach ($models as $model) {
            $byRider[$model->rider_id][] = $this->toDomain($model);
        }

        return $byRider;
    }

    public function ownedByRiders(array $riderIds): array
    {
        if ($riderIds === []) {
            return [];
        }

        $models = VehicleModel::query()
            ->whereIn('rider_id', array_values(array_unique($riderIds)))
            ->where('status', '!=', VehicleStatus::Retired->value)
            ->orderByDesc('is_primary')
            ->get();

        /** @var array<string, list<Vehicle>> $byRider */
        $byRider = [];

        foreach ($models as $model) {
            $byRider[$model->rider_id][] = $this->toDomain($model);
        }

        return $byRider;
    }

    public function primaryFor(string $riderId): ?Vehicle
    {
        $model = VehicleModel::query()
            ->where('rider_id', $riderId)
            ->where('is_primary', true)
            ->where('status', '!=', VehicleStatus::Retired->value)
            ->first();

        return $model !== null ? $this->toDomain($model) : null;
    }

    public function countFor(string $riderId): int
    {
        // Retired vehicles do not count against the per-rider limit. A rider
        // who has replaced two motorbikes over a year should not be locked out
        // of registering their current one.
        return VehicleModel::query()
            ->where('rider_id', $riderId)
            ->where('status', '!=', VehicleStatus::Retired->value)
            ->count();
    }

    public function awaitingVerification(int $limit = 50, int $offset = 0): array
    {
        return $this->hydrate(
            VehicleModel::query()
                ->whereIn('verification_state', [
                    VehicleVerificationState::Pending->value,
                    VehicleVerificationState::Unverified->value,
                ])
                ->where('status', '!=', VehicleStatus::Retired->value)
                // Oldest first: a queue ordered any other way lets a rider wait
                // indefinitely while newer submissions are handled.
                ->orderBy('created_at')
                ->offset(max(0, $offset))
                ->limit(max(1, $limit))
                ->get()
                ->all(),
        );
    }

    public function countAwaitingVerification(): int
    {
        return VehicleModel::query()
            ->whereIn('verification_state', [
                VehicleVerificationState::Pending->value,
                VehicleVerificationState::Unverified->value,
            ])
            ->where('status', '!=', VehicleStatus::Retired->value)
            ->count();
    }

    public function expiringWithin(DateTimeImmutable $now, int $days, int $limit = 200): array
    {
        $threshold = $now->modify(sprintf('+%d days', $days));

        return $this->hydrate(
            VehicleModel::query()
                ->where('verification_state', VehicleVerificationState::Verified->value)
                ->where('status', VehicleStatus::Active->value)
                ->where(function (Builder $query) use ($now, $threshold): void {
                    foreach (self::expiryColumns() as $column) {
                        $query->orWhere(function (Builder $inner) use ($column, $now, $threshold): void {
                            $inner->where($column, '>', $now)->where($column, '<=', $threshold);
                        });
                    }
                })
                ->orderBy('created_at')
                ->limit(max(1, $limit))
                ->get()
                ->all(),
        );
    }

    public function expired(DateTimeImmutable $now, int $limit = 200): array
    {
        return $this->hydrate(
            VehicleModel::query()
                ->where('verification_state', VehicleVerificationState::Verified->value)
                ->where(function (Builder $query) use ($now): void {
                    foreach (self::expiryColumns() as $column) {
                        $query->orWhere($column, '<=', $now);
                    }
                })
                ->orderBy('created_at')
                ->limit(max(1, $limit))
                ->get()
                ->all(),
        );
    }

    /**
     * Insert, or update guarded by the version the caller read.
     *
     * The update matches on `version`, so a writer who committed first has
     * already bumped it and this statement touches zero rows. That is how a
     * lost update is detected rather than silently winning — two operators
     * approving the same vehicle, or a rider editing documents while an
     * operator approves them.
     */
    public function save(Vehicle $vehicle): void
    {
        $attributes = [
            'rider_id' => $vehicle->riderId(),
            'type' => $vehicle->type()->value,
            'registration_number' => $vehicle->registrationNumber(),
            'make' => $vehicle->make(),
            'model' => $vehicle->model(),
            'colour' => $vehicle->colour(),
            'capacity_kg' => $vehicle->capacityKg(),
            'capacity_litres' => $vehicle->capacityLitres(),
            'status' => $vehicle->status()->value,
            'verification_state' => $vehicle->verificationState()->value,
            'verified_at' => $vehicle->verifiedAt(),
            'verified_by' => $vehicle->verifiedBy(),
            'verification_note' => $vehicle->verificationNote(),
            'insurance_expires_at' => $vehicle->insuranceExpiresAt(),
            'roadworthiness_expires_at' => $vehicle->roadworthinessExpiresAt(),
            'licence_expires_at' => $vehicle->licenceExpiresAt(),
            'is_primary' => $vehicle->isPrimary(),
            'updated_at' => $vehicle->updatedAt(),
        ];

        if (! VehicleModel::query()->whereKey($vehicle->id())->exists()) {
            VehicleModel::query()->insert($attributes + [
                'id' => $vehicle->id(),
                'created_at' => $vehicle->createdAt(),
                'version' => 1,
            ]);

            return;
        }

        $updated = VehicleModel::query()
            ->whereKey($vehicle->id())
            ->where('version', $vehicle->version())
            ->update($attributes + ['version' => $vehicle->version() + 1]);

        if ($updated === 0) {
            throw ConcurrencyConflict::on('vehicle', $vehicle->id());
        }
    }

    public function clearPrimaryExcept(string $riderId, string $keepVehicleId, DateTimeImmutable $now): void
    {
        VehicleModel::query()
            ->where('rider_id', $riderId)
            ->whereKeyNot($keepVehicleId)
            ->where('is_primary', true)
            ->update(['is_primary' => false, 'updated_at' => $now]);
    }

    /**
     * The SQL form of {@see Vehicle::isDispatchable()}.
     *
     * Active, verified, and no *recorded* document expiry in the past. A null
     * expiry means "not recorded", not "expired" — requiring every document up
     * front would exclude legitimate riders during rollout, and completeness is
     * judged at verification, where a human is looking.
     *
     * @param Builder<VehicleModel> $query
     * @return Builder<VehicleModel>
     */
    private function dispatchable(Builder $query, DateTimeImmutable $now): Builder
    {
        return $query
            ->where('status', VehicleStatus::Active->value)
            ->where('verification_state', VehicleVerificationState::Verified->value)
            ->where(function (Builder $inner) use ($now): void {
                foreach (self::expiryColumns() as $column) {
                    $inner->where(function (Builder $each) use ($column, $now): void {
                        $each->whereNull($column)->orWhere($column, '>', $now);
                    });
                }
            });
    }

    /** @return list<string> */
    private static function expiryColumns(): array
    {
        return ['insurance_expires_at', 'roadworthiness_expires_at', 'licence_expires_at'];
    }

    /**
     * @param array<array-key, VehicleModel> $models
     * @return list<Vehicle>
     */
    private function hydrate(array $models): array
    {
        return array_values(array_map(fn (VehicleModel $m): Vehicle => $this->toDomain($m), $models));
    }

    private function toDomain(VehicleModel $model): Vehicle
    {
        return Vehicle::reconstitute(
            id: $model->id,
            riderId: $model->rider_id,
            type: VehicleType::from($model->type),
            registrationNumber: $model->registration_number,
            make: $model->make,
            model: $model->model,
            colour: $model->colour,
            capacityKg: $model->capacity_kg,
            capacityLitres: $model->capacity_litres,
            status: VehicleStatus::from($model->status),
            verificationState: VehicleVerificationState::from($model->verification_state),
            verifiedAt: $this->toImmutable($model->verified_at),
            verifiedBy: $model->verified_by,
            verificationNote: $model->verification_note,
            insuranceExpiresAt: $this->toImmutable($model->insurance_expires_at),
            roadworthinessExpiresAt: $this->toImmutable($model->roadworthiness_expires_at),
            licenceExpiresAt: $this->toImmutable($model->licence_expires_at),
            isPrimary: $model->is_primary,
            createdAt: $this->toImmutable($model->created_at) ?? new DateTimeImmutable(),
            updatedAt: $this->toImmutable($model->updated_at) ?? new DateTimeImmutable(),
            version: $model->version,
        );
    }

    private function toImmutable(mixed $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof DateTimeImmutable) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }

        return new DateTimeImmutable((string) $value);
    }
}
