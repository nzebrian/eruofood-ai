<?php

declare(strict_types=1);

namespace EruoFood\Ai\Domain\Enum;

/** The role a message plays in a chat completion. */
enum MessageRole: string
{
    case System = 'system';
    case User = 'user';
    case Assistant = 'assistant';
}
