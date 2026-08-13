<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Application\DTO;

/**
 * One email, fully rendered and addressed, ready for a provider to transmit.
 *
 * The provider-neutral boundary: everything above this is EruoFood's engine and
 * everything below is one ESP's API. Swapping SES for Postmark is a new class
 * implementing {@see \EruoFood\Notifications\Application\Port\EmailProvider},
 * with nothing above it changed.
 *
 * `headers` exists mainly for `List-Unsubscribe`, which is attached to marketing
 * mail and never to transactional or security mail — offering a one-click opt
 * out of a password-reset notice would be worse than useless.
 */
final readonly class EmailMessage
{
    /** @param array<string, string> $headers */
    public function __construct(
        public string $toAddress,
        public ?string $toName,
        public string $subject,
        public string $htmlBody,
        public string $textBody,
        public array $headers = [],
        public ?string $correlationId = null,
    ) {
    }
}
