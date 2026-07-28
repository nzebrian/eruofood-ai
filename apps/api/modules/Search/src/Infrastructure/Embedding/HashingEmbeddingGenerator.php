<?php

declare(strict_types=1);

namespace EruoFood\Search\Infrastructure\Embedding;

use EruoFood\Search\Application\Port\EmbeddingGenerator;
use EruoFood\Search\Domain\ValueObject\Embedding;

/**
 * A deterministic, dependency-free embedder: it feature-hashes the text's tokens
 * (and their adjacent bigrams, for a little word-order signal) into a fixed
 * number of dimensions with a signed hash, then L2-normalises. Same text →
 * same vector, and texts sharing vocabulary get high cosine similarity — enough
 * for meaningful semantic ranking offline and in tests. Binding a model-backed
 * {@see EmbeddingGenerator} (via the AI engine) upgrades quality with no other
 * change, since ranking only relies on cosine.
 */
final readonly class HashingEmbeddingGenerator implements EmbeddingGenerator
{
    public function __construct(private int $dimensions)
    {
    }

    public function dimensions(): int
    {
        return $this->dimensions;
    }

    public function embed(string $text): Embedding
    {
        $vector = array_fill(0, $this->dimensions, 0.0);
        $tokens = $this->tokenize($text);

        $previous = null;
        foreach ($tokens as $token) {
            $this->add($vector, $token);
            if ($previous !== null) {
                $this->add($vector, $previous.' '.$token); // bigram
            }
            $previous = $token;
        }

        return new Embedding($this->normalise($vector));
    }

    /**
     * @return list<string>
     */
    private function tokenize(string $text): array
    {
        $lower = mb_strtolower($text);
        $parts = preg_split('/[^\p{L}\p{N}]+/u', $lower) ?: [];

        return array_values(array_filter($parts, static fn (string $t): bool => mb_strlen($t) > 1));
    }

    /**
     * @param list<float> $vector
     */
    private function add(array &$vector, string $feature): void
    {
        $hash = crc32($feature);
        $index = $hash % $this->dimensions;
        $sign = ($hash & 1) === 0 ? 1.0 : -1.0;
        $vector[$index] += $sign;
    }

    /**
     * @param list<float> $vector
     * @return list<float>
     */
    private function normalise(array $vector): array
    {
        $magnitude = 0.0;
        foreach ($vector as $value) {
            $magnitude += $value * $value;
        }
        if ($magnitude <= 0.0) {
            return $vector;
        }
        $magnitude = sqrt($magnitude);

        return array_map(static fn (float $v): float => $v / $magnitude, $vector);
    }
}
