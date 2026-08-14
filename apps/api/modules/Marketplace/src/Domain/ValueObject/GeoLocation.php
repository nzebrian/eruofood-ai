<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Domain\ValueObject;

use EruoFood\Geo\Domain\ValueObject\Coordinates;
use EruoFood\Geo\Domain\ValueObject\Haversine;
use EruoFood\Shared\Domain\Exception\InvalidArgumentException;

/**
 * A WGS-84 coordinate for the Marketplace context.
 *
 * Retained as this context's own type — its callers, repositories and tests all
 * speak it — but the arithmetic now delegates to the platform's canonical
 * {@see Haversine}. Before M25 this class carried its own copy of the formula
 * with an Earth radius of 6371.0 while Search used 6371.0088, so the same
 * journey measured differently depending on which module asked.
 *
 * Straight-line distance remains correct for proximity filtering and candidate
 * selection. It is **not** the delivery billing distance: that comes from the
 * routing provider via the Geo context, which is what M25 introduces.
 */
final readonly class GeoLocation
{
    public function __construct(
        public float $latitude,
        public float $longitude,
    ) {
        if ($latitude < -90 || $latitude > 90) {
            throw new InvalidArgumentException('Latitude must be between -90 and 90.');
        }
        if ($longitude < -180 || $longitude > 180) {
            throw new InvalidArgumentException('Longitude must be between -180 and 180.');
        }
    }

    /** Great-circle distance to another point, in kilometres. */
    public function distanceKmTo(self $other): float
    {
        return Haversine::kilometres($this->toCoordinates(), $other->toCoordinates());
    }

    /** The canonical platform coordinate, for anything that speaks to the Geo context. */
    public function toCoordinates(): Coordinates
    {
        return new Coordinates($this->latitude, $this->longitude);
    }

    public static function fromCoordinates(Coordinates $coordinates): self
    {
        return new self($coordinates->latitude, $coordinates->longitude);
    }

    /** @return array{latitude: float, longitude: float} */
    public function toArray(): array
    {
        return ['latitude' => $this->latitude, 'longitude' => $this->longitude];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self((float) $data['latitude'], (float) $data['longitude']);
    }
}
