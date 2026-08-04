<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Domain\Read;

/**
 * Port for the public search surface, implemented over the Search context's
 * application services. Returns already-shaped arrays (results/suggestions/
 * filters) since Search owns a rich, presenter-formatted result model.
 */
interface SearchReadPort
{
    /**
     * @param array<string, string> $filters
     *
     * @return array<string, mixed>
     */
    public function query(string $term, ?string $type, array $filters, int $page, int $perPage): array;

    /**
     * @return list<string>
     */
    public function suggestions(string $prefix, ?string $type): array;

    /**
     * The catalogue of supported filters/facets (static description).
     *
     * @return list<array<string, mixed>>
     */
    public function filters(): array;
}
