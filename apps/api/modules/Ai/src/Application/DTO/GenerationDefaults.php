<?php

declare(strict_types=1);

namespace EruoFood\Ai\Application\DTO;

/** Default sampling parameters applied to feature requests, from config/ai.php. */
final readonly class GenerationDefaults
{
    public function __construct(
        public int $maxTokens,
        public float $temperature,
    ) {
    }
}
