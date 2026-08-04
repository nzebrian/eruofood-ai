<?php

declare(strict_types=1);

namespace EruoFood\Search\Application\Port;

/**
 * AI-powered query understanding. Given a raw query it may correct typos,
 * normalise phrasing and surface intent terms to broaden recall. The default
 * adapter is a pass-through (returns the input unchanged) so the pipeline runs
 * fully offline; an AI-backed adapter (via the AI engine contract) can be bound
 * in when enabled.
 */
interface QueryUnderstanding
{
    /**
     * @return list<string> extra terms to OR into the query (may be empty)
     */
    public function expand(string $rawQuery, string $locale): array;
}
