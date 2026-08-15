<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Domain\Scoring;

/**
 * Why this rider scored what they scored.
 *
 * Stored with every offer, and that is the point. A scoring system whose
 * decisions cannot be explained afterwards is one nobody can debug and one no
 * rider can be given an honest answer about — "why do I never get the airport
 * runs?" deserves better than a shrug.
 *
 * Carries the factors, the weights in force at the time, and the fairness
 * multiplier separately, so a later change to the weights does not silently
 * rewrite the history of decisions made under the old ones.
 */
final readonly class ScoreBreakdown
{
    /**
     * @param array<string, float> $factors factor name => 0–1 score
     * @param array<string, float> $weights the weights in force when this was scored
     */
    public function __construct(
        public array $factors,
        public array $weights,
        public float $baseScore,
        public float $fairnessMultiplier,
        public float $finalScore,
    ) {
    }

    /** The factor that contributed most to this score. */
    public function dominantFactor(): ?string
    {
        $contributions = $this->contributions();

        if ($contributions === []) {
            return null;
        }

        arsort($contributions);

        return (string) array_key_first($contributions);
    }

    /**
     * Each factor's actual contribution — its score times its weight.
     *
     * The number a person actually wants. A factor scoring 1.0 at weight 0.05
     * matters less than one scoring 0.4 at weight 0.30, and the raw factor
     * scores alone hide that.
     *
     * @return array<string, float>
     */
    public function contributions(): array
    {
        $contributions = [];

        foreach ($this->factors as $name => $score) {
            $contributions[$name] = $score * ($this->weights[$name] ?? 0.0);
        }

        return $contributions;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'factors' => $this->factors,
            'weights' => $this->weights,
            'base_score' => round($this->baseScore, 4),
            'fairness_multiplier' => round($this->fairnessMultiplier, 4),
            'final_score' => round($this->finalScore, 4),
        ];
    }
}
