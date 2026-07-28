<?php

declare(strict_types=1);

namespace EruoFood\Search\Application\Port;

use EruoFood\Search\Domain\ValueObject\Embedding;

/**
 * Turns text into a semantic vector. The default adapter is a deterministic,
 * offline hashing embedder (feature-hashed bag-of-words, L2-normalised); a real
 * model-backed embedder (via the AI engine or a hosted service) can be bound in
 * its place without touching the search pipeline.
 */
interface EmbeddingGenerator
{
    public function embed(string $text): Embedding;

    public function dimensions(): int;
}
