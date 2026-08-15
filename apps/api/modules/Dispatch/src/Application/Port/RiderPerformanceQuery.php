<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Application\Port;

/**
 * How well riders have been performing, asked of the context that owns ratings.
 *
 * Dispatch does **not** build a second rating system. Reviews already owns
 * rider ratings, and a parallel score computed here would drift from the one
 * riders can see — so a rider would be penalised by a number nobody could show
 * them.
 *
 * The completion and acceptance rates are Dispatch's own history (it is the
 * only context that knows what was offered and what was accepted), but they are
 * read through this port too, so the full performance picture arrives from one
 * place and a later milestone can move any of it without touching scoring.
 *
 * Everything here is optional by design: a rider with no history is not scored
 * badly, they are scored neutrally. Penalising a new rider for having no
 * record would make it impossible to build one.
 */
interface RiderPerformanceQuery
{
    /**
     * Performance for many riders, keyed by rider id.
     *
     * Missing riders, and missing fields within a rider, mean "no data" — the
     * caller substitutes a neutral value rather than a zero.
     *
     * @param list<string> $riderIds
     * @return array<string, array{rating: float|null, completion_rate: float|null, acceptance_rate: float|null, deliveries: int}>
     */
    public function forRiders(array $riderIds): array;
}
