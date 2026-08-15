<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Domain\Request;

use DateTimeImmutable;
use EruoFood\Dispatch\Domain\Enum\DispatchFailureReason;
use EruoFood\Dispatch\Domain\Enum\RejectionReason;

/**
 * One round of looking for a rider — and the record of why it went that way.
 *
 * ## Why this exists at all
 *
 * "No riders available" is useless to an operator at 8pm on a Friday. It could
 * mean the fleet is busy, or that nobody's app is reporting a position, or that
 * eleven riders nearby all have lapsed insurance — three completely different
 * problems with three completely different responses, and the difference
 * between a platform outage and a paperwork backlog.
 *
 * So every attempt stores the radius it searched, how many positions the
 * geographic pass returned, how many survived eligibility, and a breakdown of
 * why the rest did not. That breakdown is the single most useful thing in this
 * context when something goes wrong.
 *
 * Append-only by construction: an attempt is written once when the round ends
 * and never edited, so the history of a struggling dispatch cannot be tidied up
 * after the fact.
 */
final readonly class DispatchAttempt
{
    /**
     * @param array<string, int> $rejectionBreakdown reason value => rider count
     */
    private function __construct(
        private string $id,
        private string $requestId,
        private int $attemptNumber,
        private int $searchRadiusMetres,
        private int $rawCandidateCount,
        private int $eligibleCandidateCount,
        private array $rejectionBreakdown,
        private ?string $offeredRiderId,
        private ?float $offeredScore,
        private ?DispatchFailureReason $outcome,
        private int $durationMs,
        private DateTimeImmutable $startedAt,
        private DateTimeImmutable $completedAt,
    ) {
    }

    /**
     * @param array<string, int> $rejectionBreakdown
     */
    public static function record(
        string $id,
        string $requestId,
        int $attemptNumber,
        int $searchRadiusMetres,
        int $rawCandidateCount,
        int $eligibleCandidateCount,
        array $rejectionBreakdown,
        DateTimeImmutable $startedAt,
        DateTimeImmutable $completedAt,
        ?string $offeredRiderId = null,
        ?float $offeredScore = null,
        ?DispatchFailureReason $outcome = null,
    ): self {
        return new self(
            $id,
            $requestId,
            $attemptNumber,
            $searchRadiusMetres,
            $rawCandidateCount,
            $eligibleCandidateCount,
            self::normaliseBreakdown($rejectionBreakdown),
            $offeredRiderId,
            $offeredScore,
            $outcome,
            max(0, (int) round(
                ($completedAt->getTimestamp() - $startedAt->getTimestamp()) * 1_000
                + ((int) $completedAt->format('v') - (int) $startedAt->format('v')),
            )),
            $startedAt,
            $completedAt,
        );
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public static function reconstitute(array $attributes): self
    {
        /** @var array<string, int> $breakdown */
        $breakdown = is_array($attributes['rejection_breakdown'] ?? null)
            ? $attributes['rejection_breakdown']
            : (array) json_decode((string) ($attributes['rejection_breakdown'] ?? '{}'), true);

        return new self(
            (string) $attributes['id'],
            (string) $attributes['request_id'],
            (int) $attributes['attempt_number'],
            (int) $attributes['search_radius_metres'],
            (int) $attributes['raw_candidate_count'],
            (int) $attributes['eligible_candidate_count'],
            self::normaliseBreakdown($breakdown),
            $attributes['offered_rider_id'] === null ? null : (string) $attributes['offered_rider_id'],
            $attributes['offered_score'] === null ? null : (float) $attributes['offered_score'],
            $attributes['outcome'] === null ? null : DispatchFailureReason::from((string) $attributes['outcome']),
            (int) $attributes['duration_ms'],
            new DateTimeImmutable((string) $attributes['started_at']),
            new DateTimeImmutable((string) $attributes['completed_at']),
        );
    }

    /**
     * The reason that eliminated the most riders, if any did.
     *
     * What an alert should lead with. "Nine of eleven had stale locations" is
     * an operator's next action; a JSON blob is homework.
     */
    public function dominantRejection(): ?RejectionReason
    {
        if ($this->rejectionBreakdown === []) {
            return null;
        }

        $sorted = $this->rejectionBreakdown;
        arsort($sorted);

        return RejectionReason::tryFrom((string) array_key_first($sorted));
    }

    public function rejectedCount(): int
    {
        return array_sum($this->rejectionBreakdown);
    }

    /**
     * A one-line explanation an operator can read without opening the JSON.
     */
    public function summary(): string
    {
        if ($this->rawCandidateCount === 0) {
            return sprintf('No riders reported a position within %dm.', $this->searchRadiusMetres);
        }

        if ($this->eligibleCandidateCount === 0) {
            $dominant = $this->dominantRejection();

            return sprintf(
                '%d rider(s) within %dm, none eligible%s.',
                $this->rawCandidateCount,
                $this->searchRadiusMetres,
                $dominant === null ? '' : sprintf(
                    ' — most commonly: %s (%d)',
                    str_replace('_', ' ', $dominant->value),
                    $this->rejectionBreakdown[$dominant->value] ?? 0,
                ),
            );
        }

        return sprintf(
            '%d of %d rider(s) within %dm were eligible.',
            $this->eligibleCandidateCount,
            $this->rawCandidateCount,
            $this->searchRadiusMetres,
        );
    }

    /**
     * Keep only reasons the enum knows, so a typo cannot become a category.
     *
     * @param array<string, int> $breakdown
     * @return array<string, int>
     */
    private static function normaliseBreakdown(array $breakdown): array
    {
        $clean = [];

        foreach ($breakdown as $reason => $count) {
            if (RejectionReason::tryFrom((string) $reason) !== null && (int) $count > 0) {
                $clean[(string) $reason] = (int) $count;
            }
        }

        return $clean;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function requestId(): string
    {
        return $this->requestId;
    }

    public function attemptNumber(): int
    {
        return $this->attemptNumber;
    }

    public function searchRadiusMetres(): int
    {
        return $this->searchRadiusMetres;
    }

    public function rawCandidateCount(): int
    {
        return $this->rawCandidateCount;
    }

    public function eligibleCandidateCount(): int
    {
        return $this->eligibleCandidateCount;
    }

    /** @return array<string, int> */
    public function rejectionBreakdown(): array
    {
        return $this->rejectionBreakdown;
    }

    public function offeredRiderId(): ?string
    {
        return $this->offeredRiderId;
    }

    public function offeredScore(): ?float
    {
        return $this->offeredScore;
    }

    public function outcome(): ?DispatchFailureReason
    {
        return $this->outcome;
    }

    public function durationMs(): int
    {
        return $this->durationMs;
    }

    public function startedAt(): DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function completedAt(): DateTimeImmutable
    {
        return $this->completedAt;
    }
}
