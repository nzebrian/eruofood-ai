<?php

declare(strict_types=1);

namespace EruoFood\Shared\Domain\Connectivity;

use DateTimeImmutable;

/**
 * A value, when it was observed, and how much that lets you trust it.
 *
 * ## Why the envelope rather than an extra field
 *
 * A response that returns `{"lat": ..., "lng": ...}` and separately
 * `{"location_updated_at": ...}` invites a client to read the first and ignore
 * the second. Binding them into one object means a consumer cannot get the
 * value without having been handed its age — the API shape does the reminding.
 *
 * ## Absence is a first-class answer
 *
 * `Observation::unavailable()` carries no value and says why. A rider whose
 * phone has been off for an hour has no position, and that is *information*:
 * it is different from a position we have not fetched, and different again from
 * a position at the restaurant. Returning null for all three loses the
 * distinction that operations needs.
 *
 * @template T
 */
final readonly class Observation
{
    /**
     * @param T|null $value
     */
    private function __construct(
        public mixed $value,
        public ?DateTimeImmutable $observedAt,
        public FreshnessState $freshness,
        public ?int $ageSeconds,
        public ?string $note,
    ) {
    }

    /**
     * Something we observed, dated, with its freshness already judged.
     *
     * @template V
     *
     * @param V $value
     * @return self<V>
     */
    public static function of(
        mixed $value,
        DateTimeImmutable $observedAt,
        DateTimeImmutable $now,
        FreshnessPolicy $policy,
    ): self {
        $age = max(0, $now->getTimestamp() - $observedAt->getTimestamp());

        return new self($value, $observedAt, $policy->judge($age), $age, null);
    }

    /**
     * Nothing to report, and the reason.
     *
     * @return self<null>
     */
    public static function unavailable(FreshnessState $freshness, ?string $note = null): self
    {
        return new self(null, null, $freshness, null, $note);
    }

    /**
     * A value we hold but cannot date.
     *
     * Deliberately `StaleUnknown` rather than `Online`. Data with no timestamp
     * is data whose age nobody has established, and defaulting that to "current"
     * is how stale information gets presented as live.
     *
     * @template V
     *
     * @param V $value
     * @return self<V>
     */
    public static function undated(mixed $value, ?string $note = null): self
    {
        return new self($value, null, FreshnessState::StaleUnknown, null, $note);
    }

    public function hasValue(): bool
    {
        return $this->value !== null;
    }

    /** Whether a dispatch or pricing decision may rest on this. */
    public function isSafeToActOn(): bool
    {
        return $this->hasValue() && $this->freshness->isSafeToActOn();
    }

    /**
     * The wire shape.
     *
     * `observed_at` and `freshness` are always present, including when there is
     * no value — a client must be able to tell "we have nothing" from "we did
     * not look".
     *
     * @return array{value: mixed, observed_at: string|null, freshness: string, age_seconds: int|null, note: string|null}
     */
    public function toArray(): array
    {
        return [
            'value' => $this->value,
            'observed_at' => $this->observedAt?->format(DATE_ATOM),
            'freshness' => $this->freshness->value,
            'age_seconds' => $this->ageSeconds,
            'note' => $this->note,
        ];
    }
}
