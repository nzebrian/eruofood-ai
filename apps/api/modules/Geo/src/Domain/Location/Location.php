<?php

declare(strict_types=1);

namespace EruoFood\Geo\Domain\Location;

use DateTimeImmutable;
use EruoFood\Geo\Domain\Enum\LocationPrecision;
use EruoFood\Geo\Domain\Enum\LocationSource;
use EruoFood\Geo\Domain\Enum\LocationVerificationStatus;
use EruoFood\Geo\Domain\Event\LocationGeocoded;
use EruoFood\Geo\Domain\Event\LocationVerificationFailed;
use EruoFood\Geo\Domain\Event\LocationVerified;
use EruoFood\Geo\Domain\Exception\GeoInvalidState;
use EruoFood\Geo\Domain\ValueObject\Coordinates;
use EruoFood\Geo\Domain\ValueObject\PostalAddress;
use EruoFood\Shared\Domain\AggregateRoot;

/**
 * A place — the platform's one geographic record.
 *
 * Everything geographic points at this: a customer's saved address, a
 * restaurant's trading address, a grocery store, a delivery zone's centre. That
 * is the point. Before M25 an address was re-modelled in each context that
 * needed one, with different fields and different assumptions, so "where is
 * this?" had several answers depending on who asked.
 *
 * A location is created from an address (which may not be geocoded yet) or from
 * coordinates (which may not have an address yet). Both halves fill in over
 * time, which is why nearly everything here is nullable and why
 * {@see LocationVerificationStatus} exists to say how far along it is.
 *
 * Precision is kept alongside the coordinates because the two are only
 * meaningful together. "6.4550, 3.3841" is a rooftop or the centre of Lagos
 * depending on how it was derived, and routing a rider to the second is a
 * different kind of mistake from routing them to the first.
 */
final class Location extends AggregateRoot
{
    private function __construct(
        private readonly string $id,
        private PostalAddress $address,
        private ?Coordinates $coordinates,
        private LocationSource $source,
        private LocationPrecision $precision,
        private LocationVerificationStatus $status,
        private ?string $provider,
        private ?string $providerPlaceId,
        private ?DateTimeImmutable $geocodedAt,
        private ?DateTimeImmutable $verifiedAt,
        private readonly DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt,
    ) {
    }

    /**
     * A location known only as text — the usual starting point when a merchant
     * types their address or a customer saves one before it is resolved.
     */
    public static function fromAddress(
        string $id,
        PostalAddress $address,
        DateTimeImmutable $now,
        LocationSource $source = LocationSource::Manual,
    ): self {
        return new self(
            $id,
            $address,
            null,
            $source,
            LocationPrecision::Unknown,
            LocationVerificationStatus::Unverified,
            null,
            null,
            null,
            null,
            $now,
            $now,
        );
    }

    /**
     * A location known only as a point — a device fix, or a pin dropped on a
     * map. The address is filled in later by reverse geocoding.
     */
    public static function fromCoordinates(
        string $id,
        Coordinates $coordinates,
        DateTimeImmutable $now,
        LocationSource $source = LocationSource::Device,
        LocationPrecision $precision = LocationPrecision::Unknown,
    ): self {
        return new self(
            $id,
            new PostalAddress(),
            $coordinates,
            $source,
            $precision,
            LocationVerificationStatus::Unverified,
            null,
            null,
            null,
            null,
            $now,
            $now,
        );
    }

    public static function reconstitute(
        string $id,
        PostalAddress $address,
        ?Coordinates $coordinates,
        LocationSource $source,
        LocationPrecision $precision,
        LocationVerificationStatus $status,
        ?string $provider,
        ?string $providerPlaceId,
        ?DateTimeImmutable $geocodedAt,
        ?DateTimeImmutable $verifiedAt,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
    ): self {
        return new self(
            $id,
            $address,
            $coordinates,
            $source,
            $precision,
            $status,
            $provider,
            $providerPlaceId,
            $geocodedAt,
            $verifiedAt,
            $createdAt,
            $updatedAt,
        );
    }

    /**
     * Record a successful geocode.
     *
     * Moves the location to `Geocoded` — resolved, not yet confirmed by anyone.
     * A `Confirmed` location is not downgraded by a re-geocode: somebody
     * checked it deliberately, and an automated pass should not quietly
     * overrule them.
     */
    public function applyGeocode(
        Coordinates $coordinates,
        PostalAddress $address,
        LocationPrecision $precision,
        string $provider,
        ?string $providerPlaceId,
        DateTimeImmutable $now,
    ): void {
        $this->coordinates = $coordinates;
        $this->address = $address;
        $this->precision = $precision;
        $this->provider = $provider;
        $this->providerPlaceId = $providerPlaceId;
        $this->source = LocationSource::Geocoded;
        $this->geocodedAt = $now;
        $this->updatedAt = $now;

        if ($this->status !== LocationVerificationStatus::Confirmed) {
            $this->status = LocationVerificationStatus::Geocoded;
        }

        $this->recordThat(new LocationGeocoded(
            $this->id,
            $coordinates->latitude,
            $coordinates->longitude,
            $address->countryCode,
            $precision->value,
            $provider,
        ));
    }

    /**
     * Record that geocoding failed.
     *
     * The location survives as an unresolved record rather than being
     * discarded, so a merchant's typed address is not lost because a provider
     * was down, and so somebody can see what needs correcting.
     */
    public function failGeocoding(string $reason, DateTimeImmutable $now): void
    {
        $this->updatedAt = $now;

        $this->recordThat(new LocationVerificationFailed($this->id, $reason));
    }

    /** A human confirmed this is right — a merchant on a map, an operator on review. */
    public function confirm(string $actorId, DateTimeImmutable $now): void
    {
        if ($this->coordinates === null) {
            throw new GeoInvalidState('A location cannot be confirmed before it has coordinates.');
        }

        $this->status = LocationVerificationStatus::Confirmed;
        $this->verifiedAt = $now;
        $this->updatedAt = $now;

        $this->recordThat(new LocationVerified($this->id, $actorId));
    }

    /** Flag a location as wrong — a rider could not find it, a customer complained. */
    public function dispute(string $reason, DateTimeImmutable $now): void
    {
        $this->status = LocationVerificationStatus::Disputed;
        $this->verifiedAt = null;
        $this->updatedAt = $now;

        $this->recordThat(new LocationVerificationFailed($this->id, $reason));
    }

    /** Move the point by hand, which necessarily un-confirms it. */
    public function repositionManually(Coordinates $coordinates, DateTimeImmutable $now): void
    {
        $this->coordinates = $coordinates;
        $this->source = LocationSource::Manual;
        $this->precision = LocationPrecision::Rooftop;
        $this->status = LocationVerificationStatus::Geocoded;
        $this->verifiedAt = null;
        $this->updatedAt = $now;
    }

    public function updateAddress(PostalAddress $address, DateTimeImmutable $now): void
    {
        $this->address = $address;
        $this->updatedAt = $now;

        // The text changed, so whatever the old text geocoded to is no longer
        // evidence for anything.
        if ($this->status === LocationVerificationStatus::Confirmed) {
            $this->status = LocationVerificationStatus::Geocoded;
            $this->verifiedAt = null;
        }
    }

    /** Whether this can be dispatched to and shown publicly. */
    public function isUsable(): bool
    {
        return $this->coordinates !== null && $this->status->isUsable();
    }

    /** Whether the point is precise enough to send somebody to. */
    public function isDeliverable(): bool
    {
        return $this->isUsable() && $this->precision->isDeliverable();
    }

    public function needsGeocoding(): bool
    {
        return $this->coordinates === null;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function address(): PostalAddress
    {
        return $this->address;
    }

    public function coordinates(): ?Coordinates
    {
        return $this->coordinates;
    }

    public function source(): LocationSource
    {
        return $this->source;
    }

    public function precision(): LocationPrecision
    {
        return $this->precision;
    }

    public function status(): LocationVerificationStatus
    {
        return $this->status;
    }

    public function provider(): ?string
    {
        return $this->provider;
    }

    public function providerPlaceId(): ?string
    {
        return $this->providerPlaceId;
    }

    public function countryCode(): ?string
    {
        return $this->address->countryCode;
    }

    public function geocodedAt(): ?DateTimeImmutable
    {
        return $this->geocodedAt;
    }

    public function verifiedAt(): ?DateTimeImmutable
    {
        return $this->verifiedAt;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
