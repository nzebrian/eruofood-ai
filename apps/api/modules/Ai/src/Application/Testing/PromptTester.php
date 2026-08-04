<?php

declare(strict_types=1);

namespace EruoFood\Ai\Application\Testing;

use EruoFood\Ai\Application\DTO\AiCompletionRequest;
use EruoFood\Ai\Application\Service\AiGateway;
use EruoFood\Ai\Application\Service\AiResponseParser;
use EruoFood\Ai\Application\Service\PromptRegistry;
use EruoFood\Ai\Domain\ValueObject\AiMessage;
use Throwable;

/**
 * The Prompt Testing Framework runner.
 *
 * Executes {@see PromptTestCase}s against the active prompt for each feature and
 * validates the model's output. Because it goes through the same
 * {@see AiGateway} as production — and tests run with the deterministic mock
 * provider — it is a real, offline regression harness for prompt changes: edit a
 * template, run the cases, see what breaks.
 */
final readonly class PromptTester
{
    public function __construct(
        private PromptRegistry $prompts,
        private AiGateway $gateway,
        private AiResponseParser $parser,
    ) {
    }

    public function run(PromptTestCase $case): PromptTestReport
    {
        $template = $this->prompts->activeFor($case->feature);
        $rendered = $template->render($case->variables);

        $request = new AiCompletionRequest(
            system: $rendered->system,
            messages: [AiMessage::user($rendered->user)],
            maxTokens: 1024,
            temperature: 0.0,
            model: $template->model(),
        );

        try {
            // Never cache test runs — always exercise the prompt end to end.
            $result = $this->gateway->generate($case->feature, $request, null, false);
        } catch (Throwable $e) {
            return new PromptTestReport($case->name, false, ['generation failed: '.$e->getMessage()], '');
        }

        $failures = $this->assert($case, $result->text);

        return new PromptTestReport($case->name, $failures === [], $failures, $result->text);
    }

    /**
     * @param list<PromptTestCase> $cases
     * @return list<PromptTestReport>
     */
    public function runAll(array $cases): array
    {
        return array_map(fn (PromptTestCase $c): PromptTestReport => $this->run($c), $cases);
    }

    /** @return list<string> */
    private function assert(PromptTestCase $case, string $output): array
    {
        $failures = [];

        foreach ($case->expectedSubstrings as $needle) {
            if (! str_contains($output, $needle)) {
                $failures[] = sprintf('expected output to contain "%s"', $needle);
            }
        }

        if ($case->expectJson || $case->requiredJsonKeys !== []) {
            try {
                $decoded = $this->parser->toArray($output);
                foreach ($case->requiredJsonKeys as $key) {
                    if (! array_key_exists($key, $decoded)) {
                        $failures[] = sprintf('expected JSON key "%s" to be present', $key);
                    }
                }
            } catch (Throwable $e) {
                $failures[] = 'expected valid JSON output: '.$e->getMessage();
            }
        }

        return $failures;
    }
}
