<?php

declare(strict_types=1);

namespace EruoFood\Geo\Interface\Http\Controller;

use EruoFood\Geo\Application\Service\AddressBookService;
use EruoFood\Geo\Application\Service\GeocodingService;
use EruoFood\Geo\Domain\Address\CustomerAddress;
use EruoFood\Geo\Domain\Enum\AddressLabel;
use EruoFood\Geo\Domain\ValueObject\Coordinates;
use EruoFood\Geo\Interface\Http\Concerns\ResolvesAuthUser;
use EruoFood\Geo\Interface\Http\Concerns\RespondsWithData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The customer's address book.
 *
 * Every route here is scoped to the authenticated caller and never takes a user
 * id from the request. That is the whole defence against IDOR on this
 * surface: there is no parameter an attacker could change to reach somebody
 * else's addresses, because the owner is read from the token and nowhere else.
 *
 * The device-position parameter deserves its own note. `device_latitude` and
 * `device_longitude` bias autocomplete towards where the customer is standing.
 * They are **never** saved as an address. A saved address is an act of choice,
 * and a phone's location is not a choice — treating it as one is how dinner
 * goes to the office somebody was walking past.
 */
final readonly class AddressController
{
    use ResolvesAuthUser;
    use RespondsWithData;

    public function __construct(
        private AddressBookService $addresses,
        private GeocodingService $geocoding,
        private GeoPresenter $presenter,
    ) {
    }

    /** The caller's own addresses, default first. */
    public function index(Request $request): JsonResponse
    {
        $userId = $this->currentUserId($request);

        return $this->data(array_map(
            fn (CustomerAddress $a): array => $this->presenter->ownAddress($a, $this->addresses->locationFor($a)),
            $this->addresses->listFor($userId),
        ));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'address' => ['required', 'string', 'min:3', 'max:500'],
            'label' => ['required', 'string', 'in:home,work,other'],
            'custom_name' => ['nullable', 'string', 'max:100'],
            'delivery_instructions' => ['nullable', 'string', 'max:500'],
            'contact_phone' => ['nullable', 'string', 'max:32'],
            'country_code' => ['nullable', 'string', 'size:2'],
            // An explicit pin, when the customer dragged the marker. It
            // outranks the geocode: somebody who moved it onto their gate knows
            // something the geocoder does not.
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'is_default' => ['nullable', 'boolean'],
        ]);

        $address = $this->addresses->add(
            userId: $this->currentUserId($request),
            addressText: (string) $data['address'],
            label: AddressLabel::from((string) $data['label']),
            customName: isset($data['custom_name']) ? (string) $data['custom_name'] : null,
            deliveryInstructions: isset($data['delivery_instructions']) ? (string) $data['delivery_instructions'] : null,
            contactPhone: isset($data['contact_phone']) ? (string) $data['contact_phone'] : null,
            countryCode: isset($data['country_code']) ? (string) $data['country_code'] : null,
            pin: Coordinates::tryFromMixed($data['latitude'] ?? null, $data['longitude'] ?? null),
            makeDefault: (bool) ($data['is_default'] ?? false),
        );

        return $this->data(
            $this->presenter->ownAddress($address, $this->addresses->locationFor($address)),
            201,
        );
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $address = $this->addresses->get($this->currentUserId($request), $id);

        return $this->data($this->presenter->ownAddress($address, $this->addresses->locationFor($address)));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'label' => ['nullable', 'string', 'in:home,work,other'],
            'custom_name' => ['nullable', 'string', 'max:100'],
            'delivery_instructions' => ['nullable', 'string', 'max:500'],
            'contact_phone' => ['nullable', 'string', 'max:32'],
        ]);

        $userId = $this->currentUserId($request);

        if (isset($data['label'])) {
            $this->addresses->rename(
                $userId,
                $id,
                AddressLabel::from((string) $data['label']),
                isset($data['custom_name']) ? (string) $data['custom_name'] : null,
            );
        }

        // `array_key_exists` rather than `isset`: clearing instructions means
        // sending null, and `isset` cannot tell that from omitting the field.
        if (array_key_exists('delivery_instructions', $data) || array_key_exists('contact_phone', $data)) {
            $this->addresses->updateInstructions(
                $userId,
                $id,
                isset($data['delivery_instructions']) ? (string) $data['delivery_instructions'] : null,
                isset($data['contact_phone']) ? (string) $data['contact_phone'] : null,
            );
        }

        $address = $this->addresses->get($userId, $id);

        return $this->data($this->presenter->ownAddress($address, $this->addresses->locationFor($address)));
    }

    /** Point an existing entry at a new place, keeping its label and history. */
    public function relocate(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'address' => ['required', 'string', 'min:3', 'max:500'],
            'country_code' => ['nullable', 'string', 'size:2'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $address = $this->addresses->relocate(
            $this->currentUserId($request),
            $id,
            (string) $data['address'],
            isset($data['country_code']) ? (string) $data['country_code'] : null,
            Coordinates::tryFromMixed($data['latitude'] ?? null, $data['longitude'] ?? null),
        );

        return $this->data($this->presenter->ownAddress($address, $this->addresses->locationFor($address)));
    }

    public function makeDefault(Request $request, string $id): JsonResponse
    {
        $address = $this->addresses->makeDefault($this->currentUserId($request), $id);

        return $this->data($this->presenter->ownAddress($address, $this->addresses->locationFor($address)));
    }

    /** Deactivate, never delete — past orders point here. */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->addresses->remove($this->currentUserId($request), $id);

        return new JsonResponse(null, 204);
    }

    /**
     * Address suggestions as the customer types.
     *
     * Rate-limited hardest of anything in this module. A client that fires on
     * every keystroke makes twenty billable calls to save one address, and the
     * bill arrives at the end of the month rather than in an error log.
     */
    public function autocomplete(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'min:3', 'max:200'],
            'country_code' => ['nullable', 'string', 'size:2'],
            // Where the phone is, used to bias suggestions. Not stored, not
            // logged, and never turned into a saved address.
            'device_latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'device_longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $suggestions = $this->geocoding->autocomplete(
            (string) $data['q'],
            Coordinates::tryFromMixed($data['device_latitude'] ?? null, $data['device_longitude'] ?? null),
            isset($data['country_code']) ? (string) $data['country_code'] : null,
        );

        return $this->data(array_map(static fn ($s): array => [
            'description' => $s->description,
            'main_text' => $s->mainText,
            'secondary_text' => $s->secondaryText,
            'provider_place_id' => $s->providerPlaceId,
        ], $suggestions));
    }
}
