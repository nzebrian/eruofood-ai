<?php

declare(strict_types=1);

namespace EruoFood\Ai\Application\Testing;

use EruoFood\Ai\Domain\Enum\AiFeature;
use EruoFood\Ai\Domain\ValueObject\PromptVariables;

/**
 * A declarative expectation for a feature's active prompt — the unit of the
 * Prompt Testing Framework.
 *
 * A case pins a set of input variables and the assertions the generated output
 * must satisfy (expected JSON shape and/or expected substrings). Running it
 * (see {@see PromptTester}) against the deterministic mock provider gives a
 * fast, offline regression check that prompt edits don't break a feature's
 * contract.
 */
final readonly class PromptTestCase
{
    /**
     * @param list<string> $requiredJsonKeys keys the decoded JSON response must contain
     * @param list<string> $expectedSubstrings substrings the raw output must contain
     */
    public function __construct(
        public string $name,
        public AiFeature $feature,
        public PromptVariables $variables,
        public bool $expectJson = false,
        public array $requiredJsonKeys = [],
        public array $expectedSubstrings = [],
    ) {
    }
}
