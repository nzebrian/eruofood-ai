<?php

declare(strict_types=1);

namespace EruoFood\Ai\Infrastructure\Provider;

use EruoFood\Ai\Domain\Enum\AiProviderName;

/** OpenAI cloud adapter (GPT models) over the `/chat/completions` API. */
final readonly class OpenAiProvider extends OpenAiCompatibleProvider
{
    public function name(): AiProviderName
    {
        return AiProviderName::OpenAi;
    }
}
