<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Interface\Http\Controller;

use EruoFood\Marketplace\Application\Service\DeliveryService;
use EruoFood\Marketplace\Application\Service\MarketplacePresenter;
use EruoFood\Marketplace\Domain\Enum\DeliveryStatus;
use EruoFood\Marketplace\Domain\ValueObject\GeoLocation;
use EruoFood\Marketplace\Interface\Http\Concerns\ResolvesAuthUser;
use EruoFood\Marketplace\Interface\Http\Concerns\RespondsWithData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Delivery assignment, status progression and live tracking. */
final readonly class DeliveryController
{
    use RespondsWithData;
    use ResolvesAuthUser;

    public function __construct(
        private DeliveryService $deliveries,
        private MarketplacePresenter $presenter,
    ) {
    }

    public function forOrder(Request $request, string $orderId): JsonResponse
    {
        $delivery = $this->deliveries->forOrder($this->currentUserId($request), $this->actorIsAdmin($request), $orderId);

        return $this->data($this->presenter->delivery($delivery));
    }

    public function assign(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate(['rider_id' => ['required', 'uuid']]);
        $delivery = $this->deliveries->assignRider(
            $this->currentUserId($request),
            $this->actorIsAdmin($request),
            $id,
            (string) $validated['rider_id'],
        );

        return $this->data($this->presenter->delivery($delivery));
    }

    public function advance(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate(['status' => ['required', 'in:picked_up,en_route,delivered,failed']]);
        $delivery = $this->deliveries->advance(
            $this->currentUserId($request),
            $this->actorIsAdmin($request),
            $id,
            DeliveryStatus::from((string) $validated['status']),
        );

        return $this->data($this->presenter->delivery($delivery));
    }

    public function track(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ]);
        $delivery = $this->deliveries->track(
            $this->currentUserId($request),
            $this->actorIsAdmin($request),
            $id,
            new GeoLocation((float) $validated['latitude'], (float) $validated['longitude']),
        );

        return $this->data($this->presenter->delivery($delivery));
    }
}
