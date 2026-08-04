<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Domain\Enum;

/** The kind of chat message. Voice is architecture-ready. */
enum MessageType: string
{
    case Text = 'text';
    case File = 'file';
    case Voice = 'voice'; // architecture-ready
}
