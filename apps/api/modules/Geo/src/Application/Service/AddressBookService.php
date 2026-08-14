<?php

declare(strict_types=1);

namespace EruoFood\Geo\Application\Service;

use DateTimeImmutable;
use EruoFood\Geo\Application\DTO\GeocodeQuery;
use EruoFood\Geo\Domain\Address\CustomerAddress;
use EruoFood\Geo\Domain\Address\CustomerAddressRepository;
use EruoFood\Geo\Domain\Enum\AddressLabel;
use EruoFood\Geo\Domain\Enum\LocationSource;
use EruoFood\Geo\Domain\Exception\GeoInvalidState;
use EruoFood\Geo\Domain\Exception\GeoNotAuthorized;
use EruoFood\Geo\Domain\Exception\GeoNotFound;
use EruoFood\Geo\Domain\Location\Location;
use EruoFood\Geo\Domain\Location\LocationRepository;
use EruoFood\Geo\Domain\ValueObject\Coordinates;
use EruoFood\Geo\Domain\ValueObject\PostalAddress;
use EruoFood\Shared\Domain\TransactionManager;

/**
 * A customer's address book.
 *
 * ## The distinction this service exists to hold
 *
 * A saved address is somewhere a customer **deliberately chose** to receive
 * deliveries. It is not wherever their phone happened to be when they opened
 * the app. Device position is a request parameter that biases suggestions and
 * pre-fills a form; it never becomes a saved address without an explicit act.
 * Conflating the two is how dinner arrives at the office somebody was standing
 * outside when they ordered — and the customer cannot explain why, because
 * they never chose that address.
 *
 * ## Authorisation
 *
 * Every address is addressed by UUID, and holding a UUID is not the same as
 * being entitled to what is behind it. Reads are scoped by `user_id` in the
 * query *and* re-checked on the loaded row, and a mismatch is reported as
 * not-found rather than forbidden — telling an attacker "that exists but isn't
 * yours" is itself a disclosure.
 */
final readonly class AddressBookService
{
    /**
     * A ceiling on saved addresses.
     *
     * Not a licensing limit: each address holds a geocode, and an unbounded
     * address book is an unbounded per-user cost with no legitimate use.
     */
    private const MAX_ADDRESSES = 25;

    public function __construct(
        private CustomerAddressRepository $addresses,
        private LocationRepository $locations,
        private GeocodingService $geocoding,
        private TransactionManager $transactions,
    ) {
    }

    /**
     * Save an address the customer typed or picked.
     *
     * The geocode happens here rather than lazily, because an address that has
     * never been resolved cannot be delivered to and the customer should learn
     * that while they are still looking at the form — not at checkout.
     */
    public function add(
        string $userId,
        string $addressText,
        AddressLabel $label,
        ?string $customName = null,
        ?string $deliveryInstructions = null,
        ?string $contactPhone = null,
        ?string $countryCode = null,
        ?Coordinates $pin = null,
        bool $makeDefault = false,
    ): CustomerAddress {
        if ($this->addresses->countActiveFor($userId) >= self::MAX_ADDRESSES) {
            throw new GeoInvalidState('You have reached the maximum number of saved addresses.');
        }

        $now = new DateTimeImmutable();
        $location = $this->resolveLocation($addressText, $countryCode, $pin, $now);

        $address = CustomerAddress::create(
            $this->addresses->nextIdentity(),
            $userId,
            $location->id(),
            $label,
            $now,
            $customName,
            $deliveryInstructions,
            $contactPhone,
            // The first address a customer saves becomes their default whether
            // they asked or not: an address book with no default makes every
            // subsequent checkout ask a question with one possible answer.
            $makeDefault || $this->addresses->countActiveFor($userId) === 0,
        );

        $this->transactions->atomic(function () use ($location, $address, $userId): void {
            $this->locations->save($location);

            if ($address->isDefault()) {
                $this->addresses->clearDefaultFor($userId, $address->id());
            }

            $this->addresses->save($address);
        });

        return $address;
    }

    /** @return list<CustomerAddress> */
    public function listFor(string $userId): array
    {
        return $this->addresses->forUser($userId);
    }

    /** The address a checkout should preselect, if the customer has one. */
    public function defaultFor(string $userId): ?CustomerAddress
    {
        return $this->addresses->defaultFor($userId);
    }

    /**
     * One address, if it belongs to the caller.
     *
     * The object-level check every read goes through. Somebody else's address
     * is reported as not-found: a 403 would confirm the id is real, which is
     * exactly the fact an enumeration attack is looking for.
     */
    public function get(string $userId, string $addressId): CustomerAddress
    {
        $address = $this->addresses->findById($addressId);

        if ($address === null || ! $address->belongsTo($userId)) {
            throw GeoNotFound::of('address', $addressId);
        }

        return $address;
    }

    /** The place an address points at — its coordinates and postal detail. */
    public function locationFor(CustomerAddress $address): ?Location
    {
        return $this->locations->findById($address->locationId());
    }

    public function rename(string $userId, string $addressId, AddressLabel $label, ?string $customName): CustomerAddress
    {
        $address = $this->get($userId, $addressId);
        $address->rename($label, $customName, new DateTimeImmutable());
        $this->addresses->save($address);

        return $address;
    }

    public function updateInstructions(string $userId, string $addressId, ?string $instructions, ?string $contactPhone): CustomerAddress
    {
        $address = $this->get($userId, $addressId);
        $address->updateInstructions($instructions, $contactPhone, new DateTimeImmutable());
        $this->addresses->save($address);

        return $address;
    }

    /**
     * Point an existing address entry at a different place.
     *
     * A new location record rather than an edit of the old one: past orders
     * reference the old geocode, and rewriting it in place would silently
     * change where a completed delivery says it went.
     */
    public function relocate(string $userId, string $addressId, string $addressText, ?string $countryCode = null, ?Coordinates $pin = null): CustomerAddress
    {
        $address = $this->get($userId, $addressId);
        $now = new DateTimeImmutable();
        $location = $this->resolveLocation($addressText, $countryCode, $pin, $now);

        $this->transactions->atomic(function () use ($location, $address, $now): void {
            $this->locations->save($location);
            $address->relocate($location->id(), $now);
            $this->addresses->save($address);
        });

        return $address;
    }

    /**
     * Make one address the default, atomically.
     *
     * Both writes in one transaction, so a customer cannot end up with two
     * defaults — or, worse, none, which would be the result of clearing
     * succeeding and setting failing.
     */
    public function makeDefault(string $userId, string $addressId): CustomerAddress
    {
        $address = $this->get($userId, $addressId);
        $now = new DateTimeImmutable();

        $this->transactions->atomic(function () use ($address, $userId, $now): void {
            $this->addresses->clearDefaultFor($userId, $address->id());
            $address->makeDefault($now);
            $this->addresses->save($address);
        });

        return $address;
    }

    /**
     * Remove an address from the book.
     *
     * Deactivated, never deleted: historical orders point at it, and an order
     * whose destination vanished is one nobody can investigate when a customer
     * disputes it. If it was the default, the most recently used survivor takes
     * over — leaving a customer with no default is a worse outcome than picking
     * for them.
     */
    public function remove(string $userId, string $addressId): void
    {
        $address = $this->get($userId, $addressId);
        $wasDefault = $address->isDefault();
        $now = new DateTimeImmutable();

        $this->transactions->atomic(function () use ($address, $userId, $wasDefault, $now): void {
            $address->deactivate($now);
            $this->addresses->save($address);

            if (! $wasDefault) {
                return;
            }

            $remaining = $this->addresses->forUser($userId);

            if ($remaining === []) {
                return;
            }

            $remaining[0]->makeDefault($now);
            $this->addresses->save($remaining[0]);
        });
    }

    /** Record that an address was used, so the book sorts by real habit. */
    public function markUsed(string $userId, string $addressId): void
    {
        $address = $this->get($userId, $addressId);
        $address->markUsed(new DateTimeImmutable());
        $this->addresses->save($address);
    }

    /**
     * Resolve text into a stored location, honouring an explicit pin.
     *
     * A dropped pin outranks the geocode. Somebody who moved the marker onto
     * their gate knows something the geocoder does not, and overruling them
     * with a rooftop match two streets away is how an address that "looks
     * right" fails every delivery.
     */
    private function resolveLocation(string $addressText, ?string $countryCode, ?Coordinates $pin, DateTimeImmutable $now): Location
    {
        $trimmed = trim($addressText);

        if ($trimmed === '') {
            throw new GeoInvalidState('An address needs some text to resolve.');
        }

        $location = Location::fromAddress(
            $this->locations->nextIdentity(),
            new PostalAddress(formatted: $trimmed, countryCode: $countryCode === null ? null : strtoupper($countryCode)),
            $now,
            LocationSource::Manual,
        );

        $result = $this->geocoding->geocode(new GeocodeQuery($trimmed, $countryCode));

        $location->applyGeocode(
            $result->coordinates,
            $result->address,
            $result->precision,
            $result->provider,
            $result->providerPlaceId,
            $now,
        );

        if ($pin !== null) {
            $location->repositionManually($pin, $now);
        }

        return $location;
    }

    /** @throws GeoNotAuthorized when a caller reaches for somebody else's book. */
    public function assertOwnership(CustomerAddress $address, string $userId): void
    {
        if (! $address->belongsTo($userId)) {
            throw new GeoNotAuthorized('That address does not belong to you.');
        }
    }
}
