<?php

declare(strict_types=1);

namespace EruoFood\Geo\Interface\Http\Controller;

use DateTimeImmutable;
use EruoFood\Geo\Application\Service\RiderLocationService;
use EruoFood\Geo\Domain\ValueObject\Coordinates;
use EruoFood\Geo\Interface\Http\Concerns\ResolvesAuthUser;
use EruoFood\Geo\Interface\Http\Concerns\RespondsWithData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Rider position reporting and reading.
 *
 * The write path takes a rider id and checks it against the rider record rather
 * than trusting it, because a rider id is a UUID in a URL and without that
 * check anybody holding one could move somebody else's marker across Lagos.
 *
 * There is deliberately **no** endpoint that lists every rider's position. That
 * is a dispatch and live-tracking concern, it belongs to a later milestone, and
 * exposing it now would publish a real-time map of where a workforce is with
 * nothing in M25 that needs it.
 */
final readonly class RiderLocationController
{
    use ResolvesAuthUser;
    use RespondsWithData;

    public function __construct(
        private RiderLocationService $riders,
        private GeoPresenter $presenter,
    ) {
    }

    /** A rider reporting where they are. */
    public function report(Request $request, string $riderId): JsonResponse
    {
        $data = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy_metres' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'heading_degrees' => ['nullable', 'numeric', 'between:0,360'],
            'speed_mps' => ['nullable', 'numeric', 'min:0', 'max:100'],
            // The device's own timestamp, so a batch of fixes uploaded after a
            // tunnel does not all claim to be "now".
            'recorded_at' => ['nullable', 'date'],
        ]);

        $location = $this->riders->report(
            riderId: $riderId,
            userId: $this->currentUserId($request),
            coordinates: Coordinates::fromMixed($data['latitude'], $data['longitude']),
            accuracyMetres: isset($data['accuracy_metres']) ? (float) $data['accuracy_metres'] : null,
            headingDegrees: isset($data['heading_degrees']) ? (float) $data['heading_degrees'] : null,
            speedMps: isset($data['speed_mps']) ? (float) $data['speed_mps'] : null,
            recordedAt: isset($data['recorded_at']) ? new DateTimeImmutable((string) $data['recorded_at']) : null,
        );

        return $this->data($this->presenter->riderLocation($location, $this->riders->staleAfterSeconds()), 202);
    }

    /** A rider reading back their own last reported position. */
    public function own(Request $request, string $riderId): JsonResponse
    {
        $location = $this->riders->own($riderId, $this->currentUserId($request));

        return $this->data($this->presenter->riderLocation($location, $this->riders->staleAfterSeconds()));
    }

    /** A rider going offline, which forgets their position entirely. */
    public function goOffline(Request $request, string $riderId): JsonResponse
    {
        $this->riders->goOffline($riderId, $this->currentUserId($request));

        return new JsonResponse(null, 204);
    }
}
