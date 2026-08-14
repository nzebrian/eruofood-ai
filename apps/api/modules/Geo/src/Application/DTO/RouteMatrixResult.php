<?php

declare(strict_types=1);

namespace EruoFood\Geo\Application\DTO;

/**
 * Distances and durations for every origin/destination pair.
 *
 * Built for M26 dispatch, where the question is "which of these twelve riders
 * is closest?" — one matrix call rather than twelve route calls, at a fraction
 * of the cost and latency.
 */
final readonly class RouteMatrixResult
{
    /**
     * @param array<int, array<int, array{distanceMetres: int, durationSeconds: int}|null>> $cells
     *                                                                                             Indexed [originIndex][destinationIndex]; null where no route exists.
     */
    public function __construct(
        public array $cells,
        public string $provider,
    ) {
    }

    /** @return array{distanceMetres: int, durationSeconds: int}|null */
    public function cell(int $originIndex, int $destinationIndex): ?array
    {
        return $this->cells[$originIndex][$destinationIndex] ?? null;
    }
}
