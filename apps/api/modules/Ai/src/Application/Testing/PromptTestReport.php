<?php

declare(strict_types=1);

namespace EruoFood\Ai\Application\Testing;

/** The outcome of running a {@see PromptTestCase}. */
final readonly class PromptTestReport
{
    /**
     * @param list<string> $failures human-readable assertion failures (empty => passed)
     */
    public function __construct(
        public string $name,
        public bool $passed,
        public array $failures,
        public string $output,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'passed' => $this->passed,
            'failures' => $this->failures,
            'output' => $this->output,
        ];
    }
}
