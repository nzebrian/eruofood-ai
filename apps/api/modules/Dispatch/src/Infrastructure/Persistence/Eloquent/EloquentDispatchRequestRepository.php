<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use EruoFood\Dispatch\Domain\Enum\DispatchState;
use EruoFood\Dispatch\Domain\Request\DispatchAttempt;
use EruoFood\Dispatch\Domain\Request\DispatchRequest;
use EruoFood\Dispatch\Domain\Request\DispatchRequestRepository;
use EruoFood\Dispatch\Infrastructure\Persistence\Eloquent\Model\DispatchAttemptModel;
use EruoFood\Dispatch\Infrastructure\Persistence\Eloquent\Model\DispatchRequestModel;
use EruoFood\Shared\Domain\Exception\ConcurrencyConflict;
use Illuminate\Support\Str;

/**
 * Eloquent persistence for {@see DispatchRequest}.
 *
 * Two concurrency mechanisms, doing different jobs:
 *
 * - {@see lockForUpdate()} stops a second worker from *doing the work*. Without
 *   it, two workers read "attempt 2 of 5", both decide to try, and both offer.
 * - The version check on {@see save()} stops a second worker from *committing*
 *   if it got that far anyway.
 *
 * Neither replaces the other, and neither replaces the partial unique index —
 * that is the last line, and the only one that holds when a bug bypasses both.
 */
final class EloquentDispatchRequestRepository implements DispatchRequestRepository
{
    /** States in which a request is still looking. Mirrors the partial unique index. */
    private const LIVE_STATES = [DispatchState::Pending->value, DispatchState::Dispatching->value];

    public function nextIdentity(): string
    {
        return (string) Str::uuid();
    }

    public function find(string $id): ?DispatchRequest
    {
        $model = DispatchRequestModel::query()->find($id);

        return $model !== null ? $this->toDomain($model) : null;
    }

    public function liveForDelivery(string $deliveryId): ?DispatchRequest
    {
        $model = DispatchRequestModel::query()
            ->where('delivery_id', $deliveryId)
            ->whereIn('state', self::LIVE_STATES)
            ->first();

        return $model !== null ? $this->toDomain($model) : null;
    }

    public function lockForUpdate(string $id): ?DispatchRequest
    {
        $model = DispatchRequestModel::query()->whereKey($id)->lockForUpdate()->first();

        return $model !== null ? $this->toDomain($model) : null;
    }

    public function claimable(int $limit = 20): array
    {
        return $this->hydrate(
            DispatchRequestModel::query()
                ->where('state', DispatchState::Pending->value)
                // Oldest first. Any other order lets one customer wait
                // indefinitely while newer orders are served.
                ->orderBy('created_at')
                ->limit(max(1, $limit))
                ->get()
                ->all(),
        );
    }

    public function timedOut(DateTimeImmutable $now, int $limit = 100): array
    {
        return $this->hydrate(
            DispatchRequestModel::query()
                ->whereIn('state', self::LIVE_STATES)
                ->where('expires_at', '<=', $now)
                ->orderBy('expires_at')
                ->limit(max(1, $limit))
                ->get()
                ->all(),
        );
    }

    public function failed(int $limit = 50): array
    {
        return $this->hydrate(
            DispatchRequestModel::query()
                ->whereIn('state', [DispatchState::Failed->value, DispatchState::Cancelled->value])
                ->orderByDesc('failed_at')
                ->limit(max(1, $limit))
                ->get()
                ->all(),
        );
    }

    public function forOrder(string $orderId): array
    {
        return $this->hydrate(
            DispatchRequestModel::query()
                ->where('order_id', $orderId)
                ->orderBy('created_at')
                ->get()
                ->all(),
        );
    }

    public function save(DispatchRequest $request): void
    {
        $attributes = [
            'delivery_id' => $request->deliveryId(),
            'order_id' => $request->orderId(),
            'vendor_id' => $request->vendorId(),
            'pickup_lat' => $request->pickupLat(),
            'pickup_lng' => $request->pickupLng(),
            'dropoff_lat' => $request->dropoffLat(),
            'dropoff_lng' => $request->dropoffLng(),
            'required_vehicle_type' => $request->requiredVehicleType()->value,
            'load_kg' => $request->loadKg(),
            'load_litres' => $request->loadLitres(),
            'zone_id' => $request->zoneId(),
            'state' => $request->state()->value,
            'attempt_count' => $request->attemptCount(),
            'max_attempts' => $request->maxAttempts(),
            'assigned_rider_id' => $request->assignedRiderId(),
            'assigned_at' => $request->assignedAt(),
            'failure_reason' => $request->failureReason()?->value,
            'failed_at' => $request->failedAt(),
            'expires_at' => $request->expiresAt(),
            'updated_at' => $request->updatedAt(),
        ];

        if (! DispatchRequestModel::query()->whereKey($request->id())->exists()) {
            DispatchRequestModel::query()->insert($attributes + [
                'id' => $request->id(),
                'created_at' => $request->createdAt(),
                'version' => 1,
            ]);

            return;
        }

        $updated = DispatchRequestModel::query()
            ->whereKey($request->id())
            ->where('version', $request->version())
            ->update($attributes + ['version' => $request->version() + 1]);

        if ($updated === 0) {
            throw ConcurrencyConflict::on('dispatch request', $request->id());
        }
    }

    public function recordAttempt(DispatchAttempt $attempt): void
    {
        DispatchAttemptModel::query()->insert([
            'id' => $attempt->id(),
            'request_id' => $attempt->requestId(),
            'attempt_number' => $attempt->attemptNumber(),
            'search_radius_metres' => $attempt->searchRadiusMetres(),
            'raw_candidate_count' => $attempt->rawCandidateCount(),
            'eligible_candidate_count' => $attempt->eligibleCandidateCount(),
            'rejection_breakdown' => json_encode($attempt->rejectionBreakdown(), JSON_THROW_ON_ERROR),
            'offered_rider_id' => $attempt->offeredRiderId(),
            'offered_score' => $attempt->offeredScore(),
            'outcome' => $attempt->outcome()?->value,
            'duration_ms' => $attempt->durationMs(),
            'started_at' => $attempt->startedAt(),
            'completed_at' => $attempt->completedAt(),
        ]);
    }

    public function attemptsFor(string $requestId): array
    {
        $models = DispatchAttemptModel::query()
            ->where('request_id', $requestId)
            ->orderBy('attempt_number')
            ->get();

        return array_values(array_map(
            static fn (DispatchAttemptModel $m): DispatchAttempt => DispatchAttempt::reconstitute([
                'id' => $m->id,
                'request_id' => $m->request_id,
                'attempt_number' => $m->attempt_number,
                'search_radius_metres' => $m->search_radius_metres,
                'raw_candidate_count' => $m->raw_candidate_count,
                'eligible_candidate_count' => $m->eligible_candidate_count,
                'rejection_breakdown' => $m->rejection_breakdown ?? [],
                'offered_rider_id' => $m->offered_rider_id,
                'offered_score' => $m->offered_score,
                'outcome' => $m->outcome,
                'duration_ms' => $m->duration_ms,
                'started_at' => $m->started_at->format('Y-m-d H:i:s'),
                'completed_at' => $m->completed_at->format('Y-m-d H:i:s'),
            ]),
            $models->all(),
        ));
    }

    /**
     * @param array<array-key, DispatchRequestModel> $models
     * @return list<DispatchRequest>
     */
    private function hydrate(array $models): array
    {
        return array_values(array_map(
            fn (DispatchRequestModel $m): DispatchRequest => $this->toDomain($m),
            $models,
        ));
    }

    private function toDomain(DispatchRequestModel $model): DispatchRequest
    {
        return DispatchRequest::reconstitute([
            'id' => $model->id,
            'delivery_id' => $model->delivery_id,
            'order_id' => $model->order_id,
            'vendor_id' => $model->vendor_id,
            'pickup_lat' => $model->pickup_lat,
            'pickup_lng' => $model->pickup_lng,
            'dropoff_lat' => $model->dropoff_lat,
            'dropoff_lng' => $model->dropoff_lng,
            'required_vehicle_type' => $model->required_vehicle_type,
            'load_kg' => $model->load_kg,
            'load_litres' => $model->load_litres,
            'zone_id' => $model->zone_id,
            'state' => $model->state,
            'attempt_count' => $model->attempt_count,
            'max_attempts' => $model->max_attempts,
            'assigned_rider_id' => $model->assigned_rider_id,
            'assigned_at' => $model->assigned_at?->format('Y-m-d H:i:s'),
            'failure_reason' => $model->failure_reason,
            'failed_at' => $model->failed_at?->format('Y-m-d H:i:s'),
            'expires_at' => $model->expires_at->format('Y-m-d H:i:s'),
            'created_at' => $model->created_at->format('Y-m-d H:i:s'),
            'updated_at' => $model->updated_at->format('Y-m-d H:i:s'),
            'version' => $model->version,
        ]);
    }
}
