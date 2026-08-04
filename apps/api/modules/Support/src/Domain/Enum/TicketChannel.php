<?php

declare(strict_types=1);

namespace EruoFood\Support\Domain\Enum;

/** How a ticket reached support. WhatsApp/Voice are architecture-ready. */
enum TicketChannel: string
{
    case Web = 'web';
    case Email = 'email';
    case Chat = 'chat';
    case InApp = 'in_app';
    case Phone = 'phone';
    case WhatsApp = 'whatsapp';
    case Voice = 'voice';
}
