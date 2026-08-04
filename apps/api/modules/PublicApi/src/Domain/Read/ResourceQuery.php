<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Domain\Read;

/**
 * A normalised read query for public data resources: pagination plus the
 * standard filter/sort/search inputs. The interface layer parses raw query
 * strings into this; adapters translate it to each source context's own query.
 */
final readonly class ResourceQuery
{
    /**
     * @param array<string, string> $filters
     */
    public function __construct(
        public int $page = 1,
        public int $perPage = 20,
        public ?string $search = null,
        public ?string $sort = null,
        public array $filters = [],
    ) {
    }
}
