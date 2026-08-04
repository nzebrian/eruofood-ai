<?php

declare(strict_types=1);

namespace EruoFood\Search\Infrastructure\Understanding;

use EruoFood\Search\Application\Port\QueryUnderstanding;

/** The default, offline query-understanding adapter: no expansion. */
final class PassthroughQueryUnderstanding implements QueryUnderstanding
{
    public function expand(string $rawQuery, string $locale): array
    {
        return [];
    }
}
