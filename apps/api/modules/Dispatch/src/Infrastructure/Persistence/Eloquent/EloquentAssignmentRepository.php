<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use EruoFood\Dispatch\Domain\Assignment\Assignment;
use EruoFood\Dispatch\Domain\Assignment\AssignmentRepository;
use EruoFood\Dispatch\Domain\Enum\AssignmentState;
use EruoFood\Dispatch\Infrastructure\Persistence\Eloquent\Model\AssignmentModel;
use EruoFood\Shared\Domain\Exception\ConcurrencyConflict;
use Illuminate\Support\Str;

/**
 * Eloquent persistence for {@see Assignment}.
 *
 * The "active" predicate is expressed here as
 * {@see AssignmentState::occupyingValues()} — the same list the partial unique
 * indexes are built from — so the query and the constraint cannot come to mean
 * different things. If they did, the application would believe a rider is free
 * while the database refuses to let them take work, or worse, the reverse.
 */
final class EloquentAssignmentRepository implements AssignmentRepository
{
    public function nextIdentity(): string
    {
        return (string) Str::uuid();
    }

    public function find(string $id): ?Assignment
    {
        $model = AssignmentModel::query()->find($id);

        return $model !== null ? $this->toDomain($model) : null;
    }

    public function activeForDelivery(string $deliveryId): ?Assignment
    {
        $model = AssignmentModel::query()
            ->where('delivery_id', $deliveryId)
            ->whereIn('state', AssignmentState::occupyingValues())
            ->first();

        return $model !== null ? $this->toDomain($model) : null;
    }

    public function activeForRider(string $riderId): ?Assignment
    {
        $model = AssignmentModel::query()
            ->where('rider_id', $riderId)
            ->whereIn('state', AssignmentState::occupyingValues())
            ->first();

        return $model !== null ? $this->toDomain($model) : null;
    }

    public function forOffer(string $offerId): ?Assignment
    {
        $model = AssignmentModel::query()->where('offer_id', $offerId)->first();

        return $model !== null ? $this->toDomain($model) : null;
    }

    public function active(int $limit = 100): array
    {
        return array_values(array_map(
            fn (AssignmentModel $m): Assignment => $this->toDomain($m),
            AssignmentModel::query()
                ->whereIn('state', AssignmentState::occupyingValues())
                ->orderByDesc('accepted_at')
                ->limit(max(1, $limit))
                ->get()
                ->all(),
        ));
    }

    public function historyForRider(string $riderId, DateTimeImmutable $since, int $limit = 50): array
    {
        return array_values(array_map(
            fn (AssignmentModel $m): Assignment => $this->toDomain($m),
            AssignmentModel::query()
                ->where('rider_id', $riderId)
                ->where('accepted_at', '>=', $since)
                ->orderByDesc('accepted_at')
                ->limit(max(1, $limit))
                ->get()
                ->all(),
        ));
    }

    public function activeCountsFor(array $riderIds): array
    {
        if ($riderIds === []) {
            return [];
        }

        $rows = AssignmentModel::query()
            ->whereIn('rider_id', array_values(array_unique($riderIds)))
            ->whereIn('state', AssignmentState::occupyingValues())
            ->selectRaw('rider_id, COUNT(*) as total')
            ->groupBy('rider_id')
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $counts[(string) $row->rider_id] = (int) $row->getAttribute('total');
        }

        return $counts;
    }

    public function save(Assignment $assignment): void
    {
        $attributes = [
            'request_id' => $assignment->requestId(),
            'offer_id' => $assignment->offerId(),
            'delivery_id' => $assignment->deliveryId(),
            'rider_id' => $assignment->riderId(),
            'vehicle_id' => $assignment->vehicleId(),
            'state' => $assignment->state()->value,
            'eta_seconds' => $assignment->etaSeconds(),
            'ended_reason' => $assignment->endedReason(),
            'ended_at' => $assignment->endedAt(),
            'updated_at' => $assignment->updatedAt(),
        ];

        if (! AssignmentModel::query()->whereKey($assignment->id())->exists()) {
            // Where two riders accepting the same delivery collide. The partial
            // unique index rejects the second insert; the caller turns that
            // into an honest "somebody else got there first".
            AssignmentModel::query()->insert($attributes + [
                'id' => $assignment->id(),
                'accepted_at' => $assignment->acceptedAt(),
                'version' => 1,
            ]);

            return;
        }

        $updated = AssignmentModel::query()
            ->whereKey($assignment->id())
            ->where('version', $assignment->version())
            ->update($attributes + ['version' => $assignment->version() + 1]);

        if ($updated === 0) {
            throw ConcurrencyConflict::on('assignment', $assignment->id());
        }
    }

    private function toDomain(AssignmentModel $model): Assignment
    {
        return Assignment::reconstitute([
            'id' => $model->id,
            'request_id' => $model->request_id,
            'offer_id' => $model->offer_id,
            'delivery_id' => $model->delivery_id,
            'rider_id' => $model->rider_id,
            'vehicle_id' => $model->vehicle_id,
            'state' => $model->state,
            'eta_seconds' => $model->eta_seconds,
            'ended_reason' => $model->ended_reason,
            'ended_at' => $model->ended_at?->format('Y-m-d H:i:s'),
            'accepted_at' => $model->accepted_at->format('Y-m-d H:i:s'),
            'updated_at' => $model->updated_at->format('Y-m-d H:i:s'),
            'version' => $model->version,
        ]);
    }
}
