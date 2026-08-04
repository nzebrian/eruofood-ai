<?php

declare(strict_types=1);

namespace EruoFood\Support\Domain\Enum;

/** Who wrote a ticket message — decides visibility and threading. */
enum MessageAuthorType: string
{
    case Customer = 'customer';
    case Agent = 'agent';
    case System = 'system';   // automation / SLA notes
    case Bot = 'bot';         // AI chatbot
}
