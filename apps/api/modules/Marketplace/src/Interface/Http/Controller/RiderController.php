<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Interface\Http\Controller;

use EruoFood\Marketplace\Application\Service\MarketplacePresenter;
use EruoFood\Marketplace\Application\Service\RiderService;
use EruoFood\Marketplace\Domain\Enum\RiderStatus;
use EruoFood\Marketplace\Domain\ValueObject\GeoLocation;
use EruoFood\Marketplace\Interface\Http\Concerns\ResolvesAuthUser;
use EruoFood\Marketplace\Interface\Http\Concerns\RespondsWithData;
use EruoFood\Marketplace\Interface\Http\Request\RiderRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Rider onboarding and self-service (availability, live location). */
final readonly class RiderController
{
    use RespondsWithData;
    use ResolvesAuthUser;

    public function __construct(
        private RiderService $riders,
        private MarketplacePresenter $presenter,
    ) {
    }

    public function store(RiderRequest $request): JsonResponse
    {
        $data = $request->validated();
        $rider = $this->riders->onboard(
            $this->currentUserId($request),
            (string) $data['name'],
            (string) $data['phone'],
            (string) $data['vehicle_type'],
        );

        return $this->data($this->presenter->rider($rider), 201);
    }

    public function me(Request $request): JsonResponse
    {
        return $this->data($this->presenter->rider($this->riders->me($this->currentUserId($request))));
    }

    public function setStatus(Request $request): JsonResponse
    {
        $validated = $request->validate(['status' => ['required', 'in:available,busy,offline']]);
        $rider = $this->riders->setStatus($this->currentUserId($request), RiderStatus::from((string) $validated['status']));

        return $this->data($this->presenter->rider($rider));
    }

    public function updateLocation(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ]);
        $rider = $this->riders->updateLocation(
            $this->currentUserId($request),
            new GeoLocation((float) $validated['latitude'], (float) $validated['longitude']),
        );

        return $this->data($this->presenter->rider($rider));
    }
}
