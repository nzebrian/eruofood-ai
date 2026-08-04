<?php

declare(strict_types=1);

namespace EruoFood\Ai\Application\Service;

use EruoFood\Ai\Application\DTO\AiCompletionRequest;
use EruoFood\Ai\Application\DTO\GeneratedContent;
use EruoFood\Ai\Application\DTO\GenerationDefaults;
use EruoFood\Ai\Domain\Enum\AiFeature;
use EruoFood\Ai\Domain\ValueObject\AiMessage;
use EruoFood\Ai\Domain\ValueObject\PromptVariables;

/**
 * Shared execution path for one-shot AI features.
 *
 * Every non-chat feature does the same three things — resolve the active prompt,
 * render it with the feature's variables, run it through the gateway — differing
 * only in whether the answer is parsed as JSON or returned as text. Collapsing
 * that here keeps the ten feature services to a few declarative lines each and
 * guarantees they all benefit identically from caching, retries, fallback and
 * usage logging.
 */
final readonly class FeatureRunner
{
    public function __construct(
        private PromptRegistry $prompts,
        private AiGateway $gateway,
        private AiResponseParser $parser,
        private GenerationDefaults $defaults,
    ) {
    }

    /** Run a feature expecting a structured (JSON) answer. */
    public function structured(AiFeature $feature, PromptVariables $vars, ?string $userId): GeneratedContent
    {
        $result = $this->execute($feature, $vars, $userId);

        return GeneratedContent::structured($this->parser->toArray($result->text), $result);
    }

    /** Run a feature expecting a plain-text answer. */
    public function text(AiFeature $feature, PromptVariables $vars, ?string $userId): GeneratedContent
    {
        $result = $this->execute($feature, $vars, $userId);

        return GeneratedContent::fromText($this->parser->toText($result->text), $result);
    }

    private function execute(
        AiFeature $feature,
        PromptVariables $vars,
        ?string $userId,
    ): \EruoFood\Ai\Application\DTO\AiCompletionResult {
        $template = $this->prompts->activeFor($feature);
        $rendered = $template->render($vars);

        $request = new AiCompletionRequest(
            system: $rendered->system,
            messages: [AiMessage::user($rendered->user)],
            maxTokens: $this->defaults->maxTokens,
            temperature: $this->defaults->temperature,
            model: $template->model(),
        );

        return $this->gateway->generate($feature, $request, $userId, $feature->isCacheable());
    }
}
