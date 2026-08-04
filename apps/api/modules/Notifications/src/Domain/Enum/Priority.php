<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Domain\Enum;

/** Notification priority. High-priority bypasses quiet hours. */
enum Priority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
}
