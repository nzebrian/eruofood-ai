<?php

declare(strict_types=1);

namespace EruoFood\Search\Domain\ValueObject;

/**
 * A dense semantic vector. Stored portably as a float list; on Postgres it is
 * mirrored into a pgvector column for native similarity. Cosine similarity is
 * defined here so the portable (non-pgvector) search path can re-rank in PHP.
 */
final readonly class Embedding
{
    /**
     * @param list<float> $values
     */
    public function __construct(public array $values)
    {
    }

    public function dimensions(): int
    {
        return count($this->values);
    }

    public function isEmpty(): bool
    {
        return $this->values === [];
    }

    /** Cosine similarity in [-1, 1]; 0 when either vector has no magnitude. */
    public function cosineTo(Embedding $other): float
    {
        $a = $this->values;
        $b = $other->values;
        $n = min(count($a), count($b));
        if ($n === 0) {
            return 0.0;
        }

        $dot = 0.0;
        $magA = 0.0;
        $magB = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $dot += $a[$i] * $b[$i];
            $magA += $a[$i] * $a[$i];
            $magB += $b[$i] * $b[$i];
        }
        if ($magA <= 0.0 || $magB <= 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($magA) * sqrt($magB));
    }

    /** @return list<float> */
    public function toArray(): array
    {
        return $this->values;
    }
}
