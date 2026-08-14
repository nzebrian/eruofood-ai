<?php

declare(strict_types=1);

namespace EruoFood\Geo\Application\DTO;

/**
 * One autocomplete suggestion.
 *
 * Carries no coordinates by design: autocomplete is a text-completion service,
 * and resolving a suggestion to a point is a separate, deliberate geocode. That
 * split stops a typeahead from silently generating a geocoding call on every
 * keystroke.
 */
final readonly class PlaceSuggestion
{
    public function __construct(
        public string $description,
        public string $providerPlaceId,
        public ?string $mainText = null,
        public ?string $secondaryText = null,
    ) {
    }
}
