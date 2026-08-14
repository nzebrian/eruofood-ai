<?php

declare(strict_types=1);

namespace EruoFood\Geo\Application\Port;

use EruoFood\Geo\Application\DTO\PlaceSuggestion;
use EruoFood\Geo\Domain\ValueObject\Coordinates;

/**
 * Address autocomplete.
 *
 * Worth its cost because it stops malformed addresses at the point of entry.
 * A delivery that fails because somebody mistyped their street is expensive in
 * a way a suggestion list is not.
 */
interface PlacesProvider
{
    public function name(): string;

    /** @return list<PlaceSuggestion> */
    public function autocomplete(string $input, ?Coordinates $bias = null, ?string $countryCode = null): array;
}
