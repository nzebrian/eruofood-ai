<?php

declare(strict_types=1);

namespace EruoFood\Support\Domain\Sla;

use DateTimeImmutable;

/**
 * The computed SLA standing of a ticket at a moment in time — kept framework-free
 * and pure so it can be unit-tested and rendered identically by the API and the
 * SLA scanner. "Breached" means a due time has passed without the corresponding
 * milestone (first response / resolution) being met.
 */
final readonly class SlaStatus
{
    public function __construct(
        public ?DateTimeImmutable $firstResponseDueAt,
        public ?DateTimeImmutable $resolutionDueAt,
        public bool $firstResponseMet,
        public bool $resolved,
        public bool $firstResponseBreached,
        public bool $resolutionBreached,
    ) {
    }

    /**
     * Evaluate SLA standing from a ticket's due times and milestones.
     */
    public static function evaluate(
        ?DateTimeImmutable $firstResponseDueAt,
        ?DateTimeImmutable $resolutionDueAt,
        ?DateTimeImmutable $firstRespondedAt,
        ?DateTimeImmutable $resolvedAt,
        DateTimeImmutable $now,
    ): self {
        $firstMet = $firstRespondedAt !== null;
        $resolved = $resolvedAt !== null;

        $firstBreached = ! $firstMet
            && $firstResponseDueAt !== null
            && $now > $firstResponseDueAt;

        // A late first response also counts as a first-response breach.
        if ($firstMet && $firstResponseDueAt !== null && $firstRespondedAt > $firstResponseDueAt) {
            $firstBreached = true;
        }

        $resolutionBreached = ! $resolved
            && $resolutionDueAt !== null
            && $now > $resolutionDueAt;

        if ($resolved && $resolutionDueAt !== null && $resolvedAt > $resolutionDueAt) {
            $resolutionBreached = true;
        }

        return new self($firstResponseDueAt, $resolutionDueAt, $firstMet, $resolved, $firstBreached, $resolutionBreached);
    }

    public function isBreached(): bool
    {
        return $this->firstResponseBreached || $this->resolutionBreached;
    }

    public function label(): string
    {
        if ($this->resolutionBreached) {
            return 'resolution_breached';
        }
        if ($this->firstResponseBreached) {
            return 'first_response_breached';
        }
        if ($this->resolved) {
            return 'met';
        }

        return 'on_track';
    }
}
