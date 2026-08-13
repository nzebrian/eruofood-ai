<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Application\DTO;

/** A rendered email in both forms. Every message carries a plain-text alternative. */
final readonly class EmailBody
{
    public function __construct(
        public string $html,
        public string $text,
    ) {
    }
}
