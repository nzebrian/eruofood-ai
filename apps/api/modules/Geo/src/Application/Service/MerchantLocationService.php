<?php

declare(strict_types=1);

namespace EruoFood\Geo\Application\Service;

use DateTimeImmutable;
use EruoFood\Geo\Application\DTO\GeocodeQuery;
use EruoFood\Geo\Domain\Enum\LocationSource;
use EruoFood\Geo\Domain\Exception\GeoAddressNotFound;
use EruoFood\Geo\Domain\Exception\GeoInvalidState;
use EruoFood\Geo\Domain\Exception\GeoNotFound;
use EruoFood\Geo\Domain\Location\Location;
use EruoFood\Geo\Domain\Location\LocationRepository;
use EruoFood\Geo\Domain\ValueObject\Coordinates;
use EruoFood\Geo\Domain\ValueObject\PostalAddress;
use EruoFood\Shared\Domain\TransactionManager;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Where merchants trade, and the point riders are sent to.
 *
 * ## Trading address, not registered address
 *
 * M24 collects a business's *registered* address for KYB — the one on the CAC
 * filing. This service manages the *trading* address, and they are frequently
 * different: a registered address is often an accountant's office or the
 * owner's home. Only the trading address is ever published, and this separation
 * is the reason a KYB approval does not automatically put an address on a
 * public listing.
 *
 * ## The M24 seam
 *
 * On KYB approval the registered address is geocoded and attached to the
 * verification profile, because operations needs to know where a business
 * actually is. That location is **private**: it is never returned by a public
 * endpoint and never becomes the merchant's map pin. The merchant sets their
 * trading address themselves, deliberately.
 *
 * Cross-context writes here are deliberately narrow. Geo updates a single
 * nullable `primary_location_id`/`location_id` column that M25's own migration
 * added to each table, and touches nothing else — no cross-context foreign
 * keys, no reaching into another module's domain.
 */
final readonly class MerchantLocationService
{
    /** The tables that carry a merchant's location pointer, by owner kind. */
    private const OWNER_TABLES = [
        'vendor' => ['table' => 'marketplace_vendors', 'column' => 'primary_location_id'],
        'store' => ['table' => 'commerce_stores', 'column' => 'primary_location_id'],
    ];

    public function __construct(
        private LocationRepository $locations,
        private GeocodingService $geocoding,
        private TransactionManager $transactions,
    ) {
    }

    /**
     * Set or replace a merchant's trading address.
     *
     * A merchant who dragged the pin outranks the geocoder — they know where
     * their gate is, and a rooftop match two streets away is how a shop that
     * "looks right" fails every delivery.
     */
    public function setTradingAddress(
        string $ownerType,
        string $ownerId,
        string $addressText,
        ?string $countryCode = null,
        ?Coordinates $pin = null,
    ): Location {
        $this->assertKnownOwner($ownerType);

        $trimmed = trim($addressText);

        if ($trimmed === '') {
            throw new GeoInvalidState('A trading address needs some text to resolve.');
        }

        $now = new DateTimeImmutable();

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

        $this->transactions->atomic(function () use ($location, $ownerType, $ownerId): void {
            $this->locations->save($location);
            $this->attachToOwner($ownerType, $ownerId, $location->id());
        });

        return $location;
    }

    /** A merchant's current trading location, if they have set one. */
    public function tradingLocationFor(string $ownerType, string $ownerId): ?Location
    {
        $this->assertKnownOwner($ownerType);

        $config = self::OWNER_TABLES[$ownerType];

        $locationId = DB::table($config['table'])->where('id', $ownerId)->value($config['column']);

        return is_string($locationId) ? $this->locations->findById($locationId) : null;
    }

    /** One location by id, for an operator investigating a delivery. */
    public function get(string $locationId): Location
    {
        $location = $this->locations->findById($locationId);

        if ($location === null) {
            throw GeoNotFound::of('location', $locationId);
        }

        return $location;
    }

    /**
     * Confirm a merchant's pin — an operator or the merchant saying "yes, there".
     *
     * A confirmed location survives a later re-geocode: somebody checked it
     * deliberately, and an automated pass should not quietly overrule them.
     */
    public function confirm(string $locationId, string $actorId): Location
    {
        $location = $this->get($locationId);

        $location->confirm($actorId, new DateTimeImmutable());
        $this->locations->save($location);

        return $location;
    }

    /** Flag a location as wrong — a rider could not find it, a customer complained. */
    public function dispute(string $locationId, string $reason): Location
    {
        $location = $this->get($locationId);

        $location->dispute($reason, new DateTimeImmutable());
        $this->locations->save($location);

        return $location;
    }

    /**
     * Geocode a KYB-approved business's registered address.
     *
     * Called from the M24 listener when a business profile is verified. Two
     * properties matter and both are deliberate:
     *
     * **It is private.** The result is attached to the verification profile,
     * not to the merchant's public listing. A registered address is often
     * somebody's home, and KYB approval is not consent to publish it.
     *
     * **It never fails the approval.** A verified business whose address the
     * geocoder could not resolve is still a verified business; returning null
     * leaves the location unresolved for somebody to correct, rather than
     * turning a mapping outage into a blocked merchant onboarding.
     *
     * @param array<string, mixed> $registeredAddress
     */
    public function geocodeRegisteredAddress(string $businessProfileId, array $registeredAddress, ?string $countryCode): ?Location
    {
        $text = $this->addressText($registeredAddress);

        if ($text === null) {
            return null;
        }

        $now = new DateTimeImmutable();

        $location = Location::fromAddress(
            $this->locations->nextIdentity(),
            new PostalAddress(formatted: $text, countryCode: $countryCode === null ? null : strtoupper($countryCode)),
            $now,
            LocationSource::Imported,
        );

        try {
            $result = $this->geocoding->geocode(new GeocodeQuery($text, $countryCode));

            $location->applyGeocode(
                $result->coordinates,
                $result->address,
                $result->precision,
                $result->provider,
                $result->providerPlaceId,
                $now,
            );
        } catch (GeoAddressNotFound $e) {
            // Kept as an unresolved record rather than discarded, so the
            // address a business actually filed is not lost and somebody can
            // see what needs correcting.
            $location->failGeocoding($e->errorCode(), $now);
        } catch (Throwable) {
            $location->failGeocoding('GEO_PROVIDER_UNAVAILABLE', $now);
        }

        $this->transactions->atomic(function () use ($location, $businessProfileId): void {
            $this->locations->save($location);

            DB::table('verification_business_profiles')
                ->where('id', $businessProfileId)
                ->update(['location_id' => $location->id()]);
        });

        return $location;
    }

    /**
     * Flatten M24's address jsonb into a line a geocoder can use.
     *
     * Tolerant by design: the shape has varied across releases, and a KYB
     * approval must not fail because a key was renamed.
     *
     * @param array<string, mixed> $address
     */
    private function addressText(array $address): ?string
    {
        $parts = [];

        foreach (['line1', 'street', 'line2', 'district', 'city', 'locality', 'state', 'admin_area', 'country'] as $key) {
            $value = $address[$key] ?? null;

            if (is_scalar($value) && trim((string) $value) !== '') {
                $parts[] = trim((string) $value);
            }
        }

        // De-duplicated because "Lagos" plausibly appears as both city and
        // state, and repeating it makes the geocode worse rather than better.
        $parts = array_values(array_unique($parts));

        return $parts === [] ? null : implode(', ', $parts);
    }

    private function attachToOwner(string $ownerType, string $ownerId, string $locationId): void
    {
        $config = self::OWNER_TABLES[$ownerType];

        $updated = DB::table($config['table'])->where('id', $ownerId)->update([$config['column'] => $locationId]);

        if ($updated === 0) {
            throw GeoNotFound::of($ownerType, $ownerId);
        }
    }

    private function assertKnownOwner(string $ownerType): void
    {
        if (! array_key_exists($ownerType, self::OWNER_TABLES)) {
            throw new GeoInvalidState('Unknown merchant type.');
        }
    }
}
