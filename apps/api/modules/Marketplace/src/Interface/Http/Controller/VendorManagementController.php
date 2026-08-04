<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Interface\Http\Controller;

use EruoFood\Marketplace\Application\Input\VendorInput;
use EruoFood\Marketplace\Application\Service\MarketplacePresenter;
use EruoFood\Marketplace\Application\Service\VendorDashboardService;
use EruoFood\Marketplace\Application\Service\VendorService;
use EruoFood\Marketplace\Domain\Vendor\Vendor;
use EruoFood\Marketplace\Interface\Http\Concerns\ResolvesAuthUser;
use EruoFood\Marketplace\Interface\Http\Concerns\RespondsWithData;
use EruoFood\Marketplace\Interface\Http\Request\VendorRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Owner-side vendor onboarding & storefront management. */
final readonly class VendorManagementController
{
    use RespondsWithData;
    use ResolvesAuthUser;

    public function __construct(
        private VendorService $vendors,
        private VendorDashboardService $dashboard,
        private MarketplacePresenter $presenter,
    ) {
    }

    public function mine(Request $request): JsonResponse
    {
        $vendors = array_map(
            fn (Vendor $v): array => $this->presenter->vendor($v),
            $this->vendors->mine($this->currentUserId($request)),
        );

        return $this->data($vendors);
    }

    public function store(VendorRequest $request): JsonResponse
    {
        $vendor = $this->vendors->register($this->currentUserId($request), VendorInput::fromArray($request->validated()));

        return $this->data($this->presenter->vendor($vendor), 201);
    }

    public function update(VendorRequest $request, string $id): JsonResponse
    {
        $vendor = $this->vendors->updateProfile(
            $this->currentUserId($request),
            $this->actorIsAdmin($request),
            $id,
            VendorInput::fromArray($request->validated()),
        );

        return $this->data($this->presenter->vendor($vendor));
    }

    public function setHours(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'hours' => ['required', 'array'],
            'hours.*.open' => ['required', 'date_format:H:i'],
            'hours.*.close' => ['required', 'date_format:H:i'],
        ]);

        $vendor = $this->vendors->setBusinessHours(
            $this->currentUserId($request),
            $this->actorIsAdmin($request),
            $id,
            $validated['hours'],
        );

        return $this->data($this->presenter->vendor($vendor));
    }

    public function setDeliveryZones(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'zones' => ['present', 'array'],
            'zones.*.name' => ['required', 'string', 'max:80'],
            'zones.*.fee_minor' => ['required', 'integer', 'min:0'],
            'zones.*.radius_km' => ['required', 'numeric', 'min:0.1'],
        ]);

        $vendor = $this->vendors->setDeliveryZones(
            $this->currentUserId($request),
            $this->actorIsAdmin($request),
            $id,
            $validated['zones'],
        );

        return $this->data($this->presenter->vendor($vendor));
    }

    public function setBranches(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'branches' => ['present', 'array'],
            'branches.*.name' => ['required', 'string', 'max:120'],
            'branches.*.address' => ['required', 'array'],
            'branches.*.address.line' => ['required', 'string', 'max:200'],
            'branches.*.address.city' => ['required', 'string', 'max:80'],
            'branches.*.address.state' => ['required', 'string', 'max:80'],
            'branches.*.phone' => ['nullable', 'string', 'max:30'],
        ]);

        $vendor = $this->vendors->setBranches(
            $this->currentUserId($request),
            $this->actorIsAdmin($request),
            $id,
            $validated['branches'],
        );

        return $this->data($this->presenter->vendor($vendor));
    }

    public function setImages(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate(['images' => ['present', 'array'], 'images.*' => ['string', 'max:500']]);

        $vendor = $this->vendors->setImages(
            $this->currentUserId($request),
            $this->actorIsAdmin($request),
            $id,
            array_values($validated['images']),
        );

        return $this->data($this->presenter->vendor($vendor));
    }

    public function dashboard(Request $request, string $id): JsonResponse
    {
        $summary = $this->dashboard->salesSummary($this->currentUserId($request), $this->actorIsAdmin($request), $id);

        return $this->data($summary->toArray());
    }
}
