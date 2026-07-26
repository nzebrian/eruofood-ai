<?php

declare(strict_types=1);

namespace EruoFood\Ai\Domain\Enum;

/**
 * The LLM providers the engine can talk to.
 *
 * Kept as a domain enum (rather than free-form strings) so provider identity is
 * type-safe across the gateway, provider registry, usage ledger and cost table.
 */
enum AiProviderName: string
{
    case Anthropic = 'anthropic';
    case OpenAi = 'openai';
    case Gemini = 'gemini';
    case Local = 'local';
    case Mock = 'mock';
}
