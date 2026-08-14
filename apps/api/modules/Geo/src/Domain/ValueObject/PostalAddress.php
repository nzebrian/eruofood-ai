<?php

declare(strict_types=1);

namespace EruoFood\Geo\Domain\ValueObject;

/**
 * A structured address, named so it works outside Nigeria.
 *
 * The administrative levels are deliberately generic. `adminArea` is a state in
 * Nigeria, a province in Kenya, a county in the UK; `subAdminArea` is an LGA
 * here and a district elsewhere. Naming the fields "state" and "lga" would have
 * baked one country's civil geography into the domain, which is precisely what
 * the existing `Marketplace\Address` (no country at all) and
 * `Commerce\Address` (`country = 'NG'` by default) already did.
 *
 * `postalCode` is optional and stays optional. Nigeria has postcodes on paper
 * and almost nobody uses them; requiring one would block address entry for the
 * launch market to satisfy a field that adds nothing.
 */
final readonly class PostalAddress
{
    public function __construct(
        /** What the user typed, or what the provider echoed back. */
        public ?string $formatted = null,
        public ?string $line1 = null,
        public ?string $line2 = null,
        /** Neighbourhood or area — often the most useful line in Nigerian addresses. */
        public ?string $district = null,
        public ?string $locality = null,
        /** State / province / region. */
        public ?string $adminArea = null,
        /** LGA / county / sub-region. */
        public ?string $subAdminArea = null,
        public ?string $postalCode = null,
        /** ISO-3166-1 alpha-2, upper case. */
        public ?string $countryCode = null,
        public ?string $countryName = null,
    ) {
    }

    /**
     * A display string, built from whatever parts exist.
     *
     * Falls back to assembling the components when the provider gave no
     * formatted line, so a manually entered address still shows sensibly.
     */
    public function displayLine(): string
    {
        if ($this->formatted !== null && trim($this->formatted) !== '') {
            return $this->formatted;
        }

        $parts = array_filter([
            $this->line1,
            $this->line2,
            $this->district,
            $this->locality,
            $this->adminArea,
            $this->postalCode,
            $this->countryName ?? $this->countryCode,
        ], static fn (?string $p): bool => $p !== null && trim($p) !== '');

        return implode(', ', $parts);
    }

    /**
     * The coarse part of an address, safe to show publicly.
     *
     * A merchant listing shows the area, not the unit number; a customer's
     * order history shows enough to recognise which address was used without
     * reproducing the whole thing on screen.
     */
    public function areaLine(): string
    {
        $parts = array_filter([
            $this->district,
            $this->locality,
            $this->adminArea,
            $this->countryName ?? $this->countryCode,
        ], static fn (?string $p): bool => $p !== null && trim($p) !== '');

        return implode(', ', $parts);
    }

    public function withCountryCode(?string $countryCode): self
    {
        return new self(
            $this->formatted,
            $this->line1,
            $this->line2,
            $this->district,
            $this->locality,
            $this->adminArea,
            $this->subAdminArea,
            $this->postalCode,
            $countryCode === null ? null : strtoupper($countryCode),
            $this->countryName,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'formatted' => $this->formatted,
            'line1' => $this->line1,
            'line2' => $this->line2,
            'district' => $this->district,
            'locality' => $this->locality,
            'admin_area' => $this->adminArea,
            'sub_admin_area' => $this->subAdminArea,
            'postal_code' => $this->postalCode,
            'country_code' => $this->countryCode,
            'country_name' => $this->countryName,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $string = static fn (string $key): ?string => isset($data[$key]) && is_scalar($data[$key]) && (string) $data[$key] !== ''
            ? (string) $data[$key]
            : null;

        $country = $string('country_code');

        return new self(
            formatted: $string('formatted'),
            line1: $string('line1'),
            line2: $string('line2'),
            district: $string('district'),
            locality: $string('locality'),
            adminArea: $string('admin_area'),
            subAdminArea: $string('sub_admin_area'),
            postalCode: $string('postal_code'),
            countryCode: $country === null ? null : strtoupper($country),
            countryName: $string('country_name'),
        );
    }
}
