<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use EruoFood\Dispatch\Domain\Enum\OfferState;
use EruoFood\Dispatch\Domain\Offer\OfferRepository;
use EruoFood\Dispatch\Domain\Offer\RiderOffer;
use EruoFood\Dispatch\Domain\Scoring\ScoreBreakdown;
use EruoFood\Dispatch\Infrastructure\Persistence\Eloquent\Model\OfferModel;
use EruoFood\Shared\Domain\Exception\ConcurrencyConflict;
use Illuminate\Support\Str;

/** Eloquent persistence for {@see RiderOffer}. */
final class EloquentOfferRepository implements OfferRepository
{
    public function nextIdentity(): string
    {
        return (string) Str::uuid();
    }

    public function find(string $id): ?RiderOffer
    {
        $model = OfferModel::query()->find($id);

        return $model !== null ? $this->toDomain($model) : null;
    }

    public function lockForUpdate(string $id): ?RiderOffer
    {
        $model = OfferModel::query()->whereKey($id)->lockForUpdate()->first();

        return $model !== null ? $this->toDomain($model) : null;
    }

    public function liveForRider(string $riderId): ?RiderOffer
    {
        $model = OfferModel::query()
            ->where('rider_id', $riderId)
            ->where('state', OfferState::Offered->value)
            ->first();

        return $model !== null ? $this->toDomain($model) : null;
    }

    public function liveForRequest(string $requestId): array
    {
        return $this->hydrate(
            OfferModel::query()
                ->where('request_id', $requestId)
                ->where('state', OfferState::Offered->value)
                ->orderByDesc('score')
                ->get()
                ->all(),
        );
    }

    public function declinedRiderIds(string $requestId): array
    {
        return array_values(array_map(
            static fn ($id): string => (string) $id,
            OfferModel::query()
                ->where('request_id', $requestId)
                // Expiry counts as a decline for exclusion purposes: a rider
                // who did not answer in forty-five seconds is not going to
                // answer the same offer a minute later, and re-offering just
                // spends the customer's remaining time budget.
                ->whereIn('state', [
                    OfferState::Declined->value,
                    OfferState::Expired->value,
                ])
                ->pluck('rider_id')
                ->all(),
        ));
    }

    public function expiredUnanswered(DateTimeImmutable $now, int $limit = 200): array
    {
        return $this->hydrate(
            OfferModel::query()
                ->where('state', OfferState::Offered->value)
                ->where('expires_at', '<=', $now)
                ->orderBy('expires_at')
                ->limit(max(1, $limit))
                ->get()
                ->all(),
        );
    }

    public function countLive(): int
    {
        return OfferModel::query()->where('state', OfferState::Offered->value)->count();
    }

    public function forRequest(string $requestId): array
    {
        return $this->hydrate(
            OfferModel::query()
                ->where('request_id', $requestId)
                ->orderBy('offered_at')
                ->get()
                ->all(),
        );
    }

    public function save(RiderOffer $offer): void
    {
        $attributes = [
            'request_id' => $offer->requestId(),
            'rider_id' => $offer->riderId(),
            'delivery_id' => $offer->deliveryId(),
            'vehicle_id' => $offer->vehicleId(),
            'score' => $offer->score(),
            'score_breakdown' => $offer->breakdown() === null
                ? null
                : json_encode($offer->breakdown()->toArray(), JSON_THROW_ON_ERROR),
            'eta_seconds' => $offer->etaSeconds(),
            'distance_metres' => $offer->distanceMetres(),
            'state' => $offer->state()->value,
            'responded_at' => $offer->respondedAt(),
            'decline_reason' => $offer->declineReason(),
            'expires_at' => $offer->expiresAt(),
        ];

        if (! OfferModel::query()->whereKey($offer->id())->exists()) {
            OfferModel::query()->insert($attributes + [
                'id' => $offer->id(),
                'offered_at' => $offer->offeredAt(),
                'version' => 1,
            ]);

            return;
        }

        $updated = OfferModel::query()
            ->whereKey($offer->id())
            ->where('version', $offer->version())
            ->update($attributes + ['version' => $offer->version() + 1]);

        if ($updated === 0) {
            // A rider tapping Accept while the sweep expires the same offer.
            // Exactly one wins; the loser is told, not silently overwritten.
            throw ConcurrencyConflict::on('offer', $offer->id());
        }
    }

    /**
     * @param array<array-key, OfferModel> $models
     * @return list<RiderOffer>
     */
    private function hydrate(array $models): array
    {
        return array_values(array_map(fn (OfferModel $m): RiderOffer => $this->toDomain($m), $models));
    }

    private function toDomain(OfferModel $model): RiderOffer
    {
        return RiderOffer::reconstitute([
            'id' => $model->id,
            'request_id' => $model->request_id,
            'rider_id' => $model->rider_id,
            'delivery_id' => $model->delivery_id,
            'vehicle_id' => $model->vehicle_id,
            'score' => $model->score,
            'eta_seconds' => $model->eta_seconds,
            'distance_metres' => $model->distance_metres,
            'state' => $model->state,
            'responded_at' => $model->responded_at?->format('Y-m-d H:i:s'),
            'decline_reason' => $model->decline_reason,
            'offered_at' => $model->offered_at->format('Y-m-d H:i:s'),
            'expires_at' => $model->expires_at->format('Y-m-d H:i:s'),
            'version' => $model->version,
        ], $this->breakdownFrom($model));
    }

    /**
     * Rebuild the stored breakdown, or nothing if it is not readable.
     *
     * Deliberately forgiving: a breakdown written by an older shape of the
     * scoring service should not make an offer unloadable, because the offer
     * still has to be answerable. Losing an explanation is bad; losing a
     * rider's ability to accept a job is worse.
     */
    private function breakdownFrom(OfferModel $model): ?ScoreBreakdown
    {
        $raw = $model->score_breakdown;

        if (! is_array($raw) || ! isset($raw['factors'], $raw['weights'])) {
            return null;
        }

        /** @var array<string, float> $factors */
        $factors = array_map(static fn ($v): float => (float) $v, (array) $raw['factors']);
        /** @var array<string, float> $weights */
        $weights = array_map(static fn ($v): float => (float) $v, (array) $raw['weights']);

        return new ScoreBreakdown(
            $factors,
            $weights,
            (float) ($raw['base_score'] ?? 0.0),
            (float) ($raw['fairness_multiplier'] ?? 1.0),
            (float) ($raw['final_score'] ?? 0.0),
        );
    }
}
