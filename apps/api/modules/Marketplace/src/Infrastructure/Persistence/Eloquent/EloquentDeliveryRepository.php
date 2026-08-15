<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use EruoFood\Marketplace\Domain\Delivery\Delivery;
use EruoFood\Marketplace\Domain\Delivery\DeliveryRepository;
use EruoFood\Marketplace\Domain\Enum\DeliveryStatus;
use EruoFood\Marketplace\Domain\ValueObject\GeoLocation;
use EruoFood\Marketplace\Infrastructure\Persistence\Eloquent\Model\DeliveryModel;
use EruoFood\Shared\Domain\Exception\ConcurrencyConflict;
use EruoFood\Shared\Domain\ValueObject\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EloquentDeliveryRepository implements DeliveryRepository
{
    public function nextIdentity(): string
    {
        return (string) Str::orderedUuid();
    }

    public function findById(string $id): ?Delivery
    {
        $m = DeliveryModel::query()->find($id);

        return $m !== null ? $this->toDomain($m) : null;
    }

    public function findByOrder(string $orderId): ?Delivery
    {
        $m = DeliveryModel::query()->where('order_id', $orderId)->first();

        return $m !== null ? $this->toDomain($m) : null;
    }

    public function activeForRider(string $riderId): array
    {
        return array_values(array_map(
            fn (DeliveryModel $m): Delivery => $this->toDomain($m),
            DeliveryModel::query()
                ->where('rider_id', $riderId)
                ->whereNotIn('status', [DeliveryStatus::Delivered->value, DeliveryStatus::Failed->value])
                ->orderBy('created_at')
                ->get()
                ->all(),
        ));
    }

    /**
     * Persist, guarded by the version the caller read.
     *
     * M26 added a second writer to this row — the dispatch bridge, which
     * advances the delivery when a rider accepts and releases it when they are
     * reassigned away. Two writers on one row is exactly what the version
     * column is for: without it, a rider marking "picked up" at the same
     * instant an operator reassigns the delivery would produce whichever write
     * landed last, silently, and the losing decision would vanish.
     *
     * A brand-new delivery inserts at version 1; an existing one must match.
     */
    public function save(Delivery $delivery): void
    {
        $existing = DeliveryModel::query()->find($delivery->id());

        if ($existing !== null) {
            /** @var array<string, mixed> $values */
            $values = [...$this->attributesOf($delivery), 'version' => $existing->version + 1];

            $updated = DB::table('marketplace_deliveries')
                ->where('id', $delivery->id())
                ->where('version', $existing->version)
                ->update($values);

            if ($updated === 0) {
                throw ConcurrencyConflict::on('delivery', $delivery->id());
            }

            return;
        }

        $model = new DeliveryModel();
        $model->id = $delivery->id();
        $model->order_id = $delivery->orderId();
        $model->vendor_id = $delivery->vendorId();
        $model->rider_id = $delivery->riderId();
        $model->status = $delivery->status()->value;
        $model->fee_minor = $delivery->fee()->minorUnits;
        $model->currency = $delivery->fee()->currency;
        $model->zone_name = $delivery->zoneName();
        $model->pickup_lat = $delivery->pickup()?->latitude;
        $model->pickup_lng = $delivery->pickup()?->longitude;
        $model->dropoff_lat = $delivery->dropoff()?->latitude;
        $model->dropoff_lng = $delivery->dropoff()?->longitude;
        $model->track_points = $delivery->trackPoints();
        $model->assigned_at = $delivery->assignedAt();
        $model->delivered_at = $delivery->deliveredAt();
        $model->created_at = $delivery->createdAt();
        $model->save();
    }

    /** @return array<string, mixed> */
    private function attributesOf(Delivery $delivery): array
    {
        return [
            'order_id' => $delivery->orderId(),
            'vendor_id' => $delivery->vendorId(),
            'rider_id' => $delivery->riderId(),
            'status' => $delivery->status()->value,
            'fee_minor' => $delivery->fee()->minorUnits,
            'currency' => $delivery->fee()->currency,
            'zone_name' => $delivery->zoneName(),
            'pickup_lat' => $delivery->pickup()?->latitude,
            'pickup_lng' => $delivery->pickup()?->longitude,
            'dropoff_lat' => $delivery->dropoff()?->latitude,
            'dropoff_lng' => $delivery->dropoff()?->longitude,
            'track_points' => json_encode($delivery->trackPoints(), JSON_THROW_ON_ERROR),
            'assigned_at' => $delivery->assignedAt(),
            'delivered_at' => $delivery->deliveredAt(),
        ];
    }

    private function toDomain(DeliveryModel $m): Delivery
    {
        return Delivery::reconstitute(
            id: $m->id,
            orderId: $m->order_id,
            vendorId: $m->vendor_id,
            riderId: $m->rider_id,
            status: DeliveryStatus::from($m->status),
            fee: new Money($m->fee_minor, $m->currency),
            zoneName: $m->zone_name,
            pickup: $m->pickup_lat !== null && $m->pickup_lng !== null ? new GeoLocation($m->pickup_lat, $m->pickup_lng) : null,
            dropoff: $m->dropoff_lat !== null && $m->dropoff_lng !== null ? new GeoLocation($m->dropoff_lat, $m->dropoff_lng) : null,
            trackPoints: $m->track_points ?? [],
            assignedAt: $m->assigned_at !== null ? DateTimeImmutable::createFromInterface($m->assigned_at) : null,
            deliveredAt: $m->delivered_at !== null ? DateTimeImmutable::createFromInterface($m->delivered_at) : null,
            createdAt: DateTimeImmutable::createFromInterface($m->created_at),
        );
    }
}
