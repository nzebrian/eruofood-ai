<?php

declare(strict_types=1);

namespace EruoFood\Geo\Interface\Http\Controller;

use EruoFood\Geo\Application\Service\MerchantLocationService;
use EruoFood\Geo\Domain\Exception\GeoNotAuthorized;
use EruoFood\Geo\Domain\ValueObject\Coordinates;
use EruoFood\Geo\Interface\Http\Concerns\ResolvesAuthUser;
use EruoFood\Geo\Interface\Http\Concerns\RespondsWithData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * A merchant's trading address.
 *
 * The public read and the owner read return different things, and that is the
 * point of having two methods rather than one with a flag. The public sees an
 * area and a point coarsened to about 110 metres — enough to place a restaurant
 * on a map. The owner sees the exact coordinates, because it is their address
 * and they need to know precisely where riders will be sent.
 *
 * The registered address from KYB never appears on either. It is frequently
 * somebody's home, and passing KYB is not consent to publish it.
 */
final readonly class MerchantLocationController
{
    use ResolvesAuthUser;
    use RespondsWithData;

    /** Where each merchant kind keeps its owning account. */
    private const OWNER_COLUMNS = [
        'vendor' => ['table' => 'marketplace_vendors', 'column' => 'owner_user_id'],
        'store' => ['table' => 'commerce_stores', 'column' => 'owner_user_id'],
    ];

    public function __construct(
        private MerchantLocationService $merchants,
        private GeoPresenter $presenter,
    ) {
    }

    /** The merchant's own view: exact, because it is theirs. */
    public function show(Request $request, string $ownerType, string $ownerId): JsonResponse
    {
        $this->assertOwner($request, $ownerType, $ownerId);

        $location = $this->merchants->tradingLocationFor($ownerType, $ownerId);

        return $this->data([
            'location' => $location === null ? null : $this->presenter->merchantLocation($location),
        ]);
    }

    /** Set or replace the trading address. */
    public function store(Request $request, string $ownerType, string $ownerId): JsonResponse
    {
        $this->assertOwner($request, $ownerType, $ownerId);

        $data = $request->validate([
            'address' => ['required', 'string', 'min:3', 'max:500'],
            'country_code' => ['nullable', 'string', 'size:2'],
            // A pin the merchant dragged. It outranks the geocode — they know
            // where their gate is, and a rooftop match two streets away is how
            // a shop that "looks right" fails every delivery.
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $location = $this->merchants->setTradingAddress(
            $ownerType,
            $ownerId,
            (string) $data['address'],
            isset($data['country_code']) ? (string) $data['country_code'] : null,
            Coordinates::tryFromMixed($data['latitude'] ?? null, $data['longitude'] ?? null),
        );

        return $this->data($this->presenter->merchantLocation($location), 201);
    }

    /** The merchant confirming their own pin is right. */
    public function confirm(Request $request, string $ownerType, string $ownerId): JsonResponse
    {
        $this->assertOwner($request, $ownerType, $ownerId);

        $location = $this->merchants->tradingLocationFor($ownerType, $ownerId);

        if ($location === null) {
            return $this->data(['location' => null], 404);
        }

        return $this->data($this->presenter->merchantLocation(
            $this->merchants->confirm($location->id(), $this->currentUserId($request)),
        ));
    }

    /**
     * The public view: coarsened, and only for merchants that have one.
     *
     * Unauthenticated by design — a customer browsing a menu needs to know
     * roughly where the restaurant is, and that is already public information.
     * What is not public is the precision.
     */
    public function publicShow(Request $request, string $ownerType, string $ownerId): JsonResponse
    {
        $location = $this->merchants->tradingLocationFor($ownerType, $ownerId);

        // An unresolved or disputed location is withheld rather than published
        // imprecisely: a pin in the wrong place is worse than no pin.
        if ($location === null || ! $location->isUsable()) {
            return $this->data(['location' => null]);
        }

        return $this->data(['location' => $this->presenter->publicLocation($location)]);
    }

    /**
     * A merchant may only manage their own location.
     *
     * Checked against the owning account on the merchant record rather than
     * trusted from the URL — the same object-level rule the rest of the
     * platform applies to orders and wallets.
     */
    private function assertOwner(Request $request, string $ownerType, string $ownerId): void
    {
        if (! array_key_exists($ownerType, self::OWNER_COLUMNS)) {
            throw new GeoNotAuthorized('Unknown merchant type.');
        }

        $config = self::OWNER_COLUMNS[$ownerType];
        $owner = DB::table($config['table'])->where('id', $ownerId)->value($config['column']);

        if ($owner === null || $owner !== $this->currentUserId($request)) {
            throw new GeoNotAuthorized('That merchant does not belong to you.');
        }
    }
}
